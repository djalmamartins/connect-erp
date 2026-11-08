<?php

declare(strict_types=1);

namespace Source\Services;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Support\Audit;

/**
 * Checklist de chegada do condomínio (ADR 0013, docs/specs/modules/checklist-de-chegada.md).
 *
 * O catálogo `onboarding_item_templates` define o que precisa ser verificado quando a
 * Connect assume um condomínio. Cada condomínio recebe uma cópia dos itens ativos em
 * `condominium_onboarding_items`. Item com pendência ganha protocolo e prazo; item
 * regular com validade volta a aparecer quando vence (manter em dia).
 *
 * O escopo por condomínio (CondominiumScope) é verificado pelos controllers antes de
 * chamar este serviço; aqui toda consulta de item também filtra por condominium_id.
 */
final class OnboardingChecklist
{
    public const STATUSES = ['pending', 'regular', 'action_required', 'in_progress', 'not_applicable'];
    public const CATEGORIES = ['documentacao', 'seguranca', 'manutencao', 'seguros', 'financeiro', 'pessoas'];
    /** Situações que exigem protocolo (há algo a providenciar). */
    public const OPEN_STATUSES = ['action_required', 'in_progress'];
    public const EXPIRING_DAYS = 30;

    public const STATUS_LABELS = [
        'pending' => 'A verificar', 'regular' => 'Em dia', 'action_required' => 'Falta providenciar',
        'in_progress' => 'Em andamento', 'not_applicable' => 'Não se aplica',
    ];
    public const SITUATION_LABELS = self::STATUS_LABELS + [
        'expiring' => 'Vence em breve', 'expired' => 'Vencido', 'overdue' => 'Prazo vencido',
    ];
    public const CATEGORY_LABELS = [
        'documentacao' => 'Documentação', 'seguranca' => 'Segurança e Bombeiros', 'manutencao' => 'Manutenção',
        'seguros' => 'Seguros', 'financeiro' => 'Financeiro e fiscal', 'pessoas' => 'Funcionários',
    ];

    private PDO $pdo;
    private DateTimeImmutable $today;

    public function __construct(?PDO $pdo = null, ?DateTimeImmutable $today = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /**
     * Cria no condomínio os itens ativos do catálogo que ainda não existem. Idempotente:
     * pode ser chamado no cadastro do condomínio e de novo quando o catálogo ganhar itens.
     *
     * @return int quantidade de itens criados
     */
    public function start(int $condominiumId, ?int $userId = null): int
    {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO condominium_onboarding_items
                (condominium_id, template_id, category, title, status, created_by, updated_by)
            SELECT :condo, t.id, t.category, t.title, 'pending', :created_by, :updated_by
            FROM onboarding_item_templates t
            WHERE t.active = 1
            ORDER BY t.id");
        $stmt->execute(['condo' => $condominiumId, 'created_by' => $userId, 'updated_by' => $userId]);
        $created = $stmt->rowCount();
        if ($created > 0) {
            Audit::record('create', 'condominium_onboarding_items', $condominiumId, null, ['condominium_id' => $condominiumId, 'items_created' => $created]);
        }
        return $created;
    }

    public function started(int $condominiumId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM condominium_onboarding_items WHERE condominium_id = :condo LIMIT 1');
        $stmt->execute(['condo' => $condominiumId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> itens do condomínio na ordem do catálogo */
    public function items(int $condominiumId): array
    {
        $stmt = $this->pdo->prepare("SELECT i.id, i.template_id, i.category, i.title, i.status, i.protocol, i.due_date,
                i.expires_at, i.notes, i.responsible_user_id, i.resolved_at, i.alerted_situation, i.updated_at,
                t.code, t.description, t.renewal_months, t.default_due_days,
                CONCAT_WS(' ', u.first_name, u.last_name) AS responsible_name
            FROM condominium_onboarding_items i
            LEFT JOIN onboarding_item_templates t ON t.id = i.template_id
            LEFT JOIN users u ON u.id = i.responsible_user_id
            WHERE i.condominium_id = :condo
            ORDER BY FIELD(i.category, 'documentacao','seguranca','manutencao','seguros','financeiro','pessoas'),
                COALESCE(t.position, 999), i.id");
        $stmt->execute(['condo' => $condominiumId]);
        return array_map(fn(array $row): array => $this->present($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    public function item(int $condominiumId, int $itemId): array
    {
        foreach ($this->items($condominiumId) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }
        throw new OnboardingItemNotFound($itemId);
    }

    /**
     * Resumo para o cabeçalho da tela e para a API.
     *
     * @param list<array<string, mixed>>|null $items
     * @return array{total:int, done:int, open:int, pending:int, attention:int, progress:int, by_situation:array<string,int>}
     */
    public function summary(int $condominiumId, ?array $items = null): array
    {
        $items ??= $this->items($condominiumId);
        $bySituation = array_fill_keys(array_keys(self::SITUATION_LABELS), 0);
        $done = 0;
        foreach ($items as $item) {
            $bySituation[$item['situation']]++;
            if (in_array($item['situation'], ['regular', 'expiring', 'not_applicable'], true)) {
                $done++;
            }
        }
        $total = count($items);
        return [
            'total' => $total,
            'done' => $done,
            'open' => $bySituation['action_required'] + $bySituation['in_progress'] + $bySituation['overdue'],
            'pending' => $bySituation['pending'],
            'attention' => $bySituation['expired'] + $bySituation['overdue'] + $bySituation['expiring'],
            'progress' => $total > 0 ? (int)floor($done * 100 / $total) : 0,
            'by_situation' => $bySituation,
        ];
    }

    /**
     * Atualiza situação, prazo, validade, observações e responsável de um item.
     * Ao marcar "falta providenciar" ou "em andamento", gera protocolo e prazo padrão.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> item atualizado
     * @throws OnboardingValidationError|OnboardingItemNotFound
     */
    public function update(int $condominiumId, int $itemId, array $input, ?int $userId = null): array
    {
        $before = $this->row($condominiumId, $itemId);
        $errors = [];

        $status = array_key_exists('status', $input) ? (string)$input['status'] : (string)$before['status'];
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'Situação inválida.';
        }
        $dueDate = array_key_exists('due_date', $input) ? $this->date($input['due_date'], 'due_date', $errors) : $before['due_date'];
        $expiresAt = array_key_exists('expires_at', $input) ? $this->date($input['expires_at'], 'expires_at', $errors) : $before['expires_at'];
        $notes = array_key_exists('notes', $input) ? trim((string)$input['notes']) : (string)($before['notes'] ?? '');
        if (mb_strlen($notes) > 2000) {
            $errors['notes'] = 'Observações com no máximo 2000 caracteres.';
        }
        $responsible = $before['responsible_user_id'] !== null ? (int)$before['responsible_user_id'] : null;
        if (array_key_exists('responsible_user_id', $input)) {
            $responsible = $this->responsible($input['responsible_user_id'], $errors);
        }
        if ($errors !== []) {
            throw new OnboardingValidationError($errors);
        }

        $protocol = $before['protocol'];
        $resolvedAt = $before['resolved_at'];
        if (in_array($status, self::OPEN_STATUSES, true)) {
            $protocol ??= $this->protocol($itemId);
            $dueDate ??= $this->today->modify('+' . (int)($before['default_due_days'] ?? 30) . ' days')->format('Y-m-d');
            $resolvedAt = null;
        } elseif (in_array($status, ['regular', 'not_applicable'], true)) {
            if ($status !== $before['status'] || $resolvedAt === null) {
                $resolvedAt = $this->today->format('Y-m-d') . ' ' . date('H:i:s');
            }
            if ($status === 'regular' && $expiresAt === null && $before['renewal_months'] !== null) {
                $expiresAt = $this->today->modify('+' . (int)$before['renewal_months'] . ' months')->format('Y-m-d');
            }
        } else {
            $resolvedAt = null;
        }

        $stmt = $this->pdo->prepare('UPDATE condominium_onboarding_items SET status = :status, protocol = :protocol,
                due_date = :due_date, expires_at = :expires_at, notes = :notes, responsible_user_id = :responsible,
                resolved_at = :resolved_at, updated_by = :updated_by, alerted_situation = NULL, alerted_at = NULL
            WHERE id = :id AND condominium_id = :condo');
        $stmt->execute([
            'status' => $status, 'protocol' => $protocol, 'due_date' => $dueDate, 'expires_at' => $expiresAt,
            'notes' => $notes !== '' ? $notes : null, 'responsible' => $responsible, 'resolved_at' => $resolvedAt,
            'updated_by' => $userId, 'id' => $itemId, 'condo' => $condominiumId,
        ]);

        $after = $this->row($condominiumId, $itemId);
        Audit::record('update', 'condominium_onboarding_items', $itemId, $this->auditable($before), $this->auditable($after));
        return $this->item($condominiumId, $itemId);
    }

    /**
     * Item avulso do condomínio (não vem do catálogo), por exemplo uma pendência
     * específica encontrada na chegada. Já nasce com protocolo.
     *
     * @return array<string, mixed>
     * @throws OnboardingValidationError
     */
    public function addCustom(int $condominiumId, string $category, string $title, ?int $userId = null): array
    {
        $title = trim($title);
        $errors = [];
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors['category'] = 'Categoria inválida.';
        }
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = 'Informe a descrição do item (até 160 caracteres).';
        }
        if ($errors !== []) {
            throw new OnboardingValidationError($errors);
        }
        $stmt = $this->pdo->prepare("INSERT INTO condominium_onboarding_items
            (condominium_id, template_id, category, title, status, created_by, updated_by)
            VALUES (:condo, NULL, :category, :title, 'pending', :created_by, :updated_by)");
        $stmt->execute(['condo' => $condominiumId, 'category' => $category, 'title' => $title, 'created_by' => $userId, 'updated_by' => $userId]);
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'condominium_onboarding_items', $id, null, ['condominium_id' => $condominiumId, 'category' => $category, 'title' => $title]);
        return $this->update($condominiumId, $id, ['status' => 'action_required'], $userId);
    }

    /** @return list<array{id:int,name:string}> usuários que podem ser responsáveis pelos itens */
    public function responsibleOptions(): array
    {
        $stmt = $this->pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) AS name FROM users WHERE status = 'confirmed' AND level >= 5 ORDER BY first_name, last_name");
        return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        $status = (string)$row['status'];
        $situation = $status;
        $today = $this->today->format('Y-m-d');
        $limit = $this->today->modify('+' . self::EXPIRING_DAYS . ' days')->format('Y-m-d');
        if ($status === 'regular' && $row['expires_at'] !== null) {
            if ($row['expires_at'] < $today) {
                $situation = 'expired';
            } elseif ($row['expires_at'] <= $limit) {
                $situation = 'expiring';
            }
        } elseif (in_array($status, self::OPEN_STATUSES, true) && $row['due_date'] !== null && $row['due_date'] < $today) {
            $situation = 'overdue';
        }
        return [
            'id' => (int)$row['id'],
            'code' => $row['code'] !== null ? (string)$row['code'] : null,
            'custom' => $row['template_id'] === null,
            'category' => (string)$row['category'],
            'title' => (string)$row['title'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'status' => $status,
            'situation' => $situation,
            'protocol' => $row['protocol'] !== null ? (string)$row['protocol'] : null,
            'due_date' => $row['due_date'] !== null ? (string)$row['due_date'] : null,
            'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
            'renewal_months' => $row['renewal_months'] !== null ? (int)$row['renewal_months'] : null,
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'responsible' => $row['responsible_user_id'] !== null
                ? ['id' => (int)$row['responsible_user_id'], 'name' => trim((string)$row['responsible_name'])] : null,
            'resolved_at' => $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
            'alerted_situation' => $row['alerted_situation'] !== null ? (string)$row['alerted_situation'] : null,
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $condominiumId, int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, t.default_due_days, t.renewal_months
            FROM condominium_onboarding_items i
            LEFT JOIN onboarding_item_templates t ON t.id = i.template_id
            WHERE i.id = :id AND i.condominium_id = :condo');
        $stmt->execute(['id' => $itemId, 'condo' => $condominiumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new OnboardingItemNotFound($itemId);
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function auditable(array $row): array
    {
        return array_intersect_key($row, array_flip(['condominium_id', 'status', 'protocol', 'due_date', 'expires_at', 'notes', 'responsible_user_id', 'resolved_at']));
    }

    /** Protocolo legível e único: CHK-AAAA-NNNNNN (ano da abertura + id do item). */
    private function protocol(int $itemId): string
    {
        return sprintf('CHK-%s-%06d', $this->today->format('Y'), $itemId);
    }

    /** @param array<string, string> $errors */
    private function date(mixed $value, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        if ($date === false || $date->format('Y-m-d') !== (string)$value) {
            $errors[$field] = 'Data inválida (use AAAA-MM-DD).';
            return null;
        }
        return $date->format('Y-m-d');
    }

    /** @param array<string, string> $errors */
    private function responsible(mixed $value, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $errors['responsible_user_id'] = 'Responsável inválido.';
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = :id AND status = 'confirmed'");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetchColumn()) {
            $errors['responsible_user_id'] = 'Responsável não encontrado.';
            return null;
        }
        return (int)$id;
    }
}
