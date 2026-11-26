<?php

declare(strict_types=1);

namespace Source\Services\Proposals;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Support\Access;
use Source\Support\Audit;
use Source\Support\Proposal\ProposalService;

/**
 * Propostas (ADR 0639, 0644 e 0653): pedidas no site ou cadastradas pela equipe no ERP, na tabela `proposals`,
 * lidas e atualizadas aqui para a tela do ERP (CN-011), a API /api/v1/proposals e a consulta do Studio.
 * Criar, gerar e enviar a proposta em PDF: ProposalComposer.
 *
 * Quem pode: `proposals.manage` (a mesma regra do Studio e de quem recebe o aviso no sininho).
 * As propostas são da Connect (comercial), não de um condomínio: não há condominium_id aqui.
 */
final class ProposalDesk
{
    public const PERMISSION = 'proposals.manage';

    /** Situações, na ordem do funil, com o rótulo e o tom do selo. */
    public const STATUSES = [
        'new' => ['Nova', 'warning'],
        'contacted' => ['Contatada', 'info'],
        'qualified' => ['Qualificada', 'info'],
        'proposal_sent' => ['Proposta enviada', 'info'],
        'won' => ['Fechada', 'success'],
        'lost' => ['Perdida', 'plain'],
        'archived' => ['Arquivada', 'plain'],
    ];

    public const PROFILES = ['sindico' => 'Síndico', 'conselheiro' => 'Conselheiro', 'morador' => 'Morador', 'administrador' => 'Administrador', 'outro' => 'Outro'];

    public const PER_PAGE = 20;

    private const COLUMNS = 'id, protocol, name, email, whatsapp, condominium, units, profile, message, status, assigned_to, source_url, origin, created_by, price_admin_unit_cents, price_syndic_unit_cents, min_monthly_cents, consent_at, marketing_consent_at, contacted_at, created_at, updated_at';

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public static function allowed(User $user): bool
    {
        return Access::can(self::PERMISSION, $user);
    }

    public static function label(string $status): string
    {
        return self::STATUSES[$status][0] ?? $status;
    }

    /** Número de propostas novas (ainda sem contato): o selo do menu e o alerta da tela. */
    public function newCount(): int
    {
        return (int)$this->pdo()->query("SELECT COUNT(*) FROM proposals WHERE status = 'new'")->fetchColumn();
    }

    /** @return array{total:int,new:int,progress:int,won:int} */
    public function stats(): array
    {
        $row = $this->pdo()->query("SELECT COUNT(*) total, COALESCE(SUM(status='new'),0) new_count, COALESCE(SUM(status IN ('contacted','qualified','proposal_sent')),0) progress_count, COALESCE(SUM(status='won'),0) won_count FROM proposals")->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (int)($row['total'] ?? 0), 'new' => (int)($row['new_count'] ?? 0), 'progress' => (int)($row['progress_count'] ?? 0), 'won' => (int)($row['won_count'] ?? 0)];
    }

    /**
     * Lista com busca (nome, e-mail, condomínio ou protocolo) e situação, mais recentes primeiro.
     *
     * @return array{data:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function list(string $search = '', string $status = '', int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $search = mb_substr(trim($search), 0, 120);
        $perPage = max(1, min(100, $perPage));
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(name LIKE :q1 OR email LIKE :q2 OR condominium LIKE :q3 OR protocol LIKE :q4)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        if (isset(self::STATUSES[$status])) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM proposals' . $sqlWhere);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM proposals' . $sqlWhere . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);
        $rows = array_map([$this, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM proposals WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->present($row) : null;
    }

    /**
     * Versões da proposta em PDF (geradas no ERP, ADR 0653, ou antes no Studio), mais novas primeiro.
     *
     * @return list<array{id:int,subject:string,template_type:string,status:string,has_pdf:bool,queued_at:?string,created_at:string}>
     */
    public function responses(int $proposalId): array
    {
        $stmt = $this->pdo()->prepare('SELECT id, subject, template_type, status, pdf_path, queued_at, created_at FROM proposal_responses WHERE proposal_id = :id ORDER BY id DESC');
        $stmt->execute([':id' => $proposalId]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'subject' => (string)$r['subject'], 'template_type' => (string)$r['template_type'],
            'status' => (string)$r['status'], 'has_pdf' => (string)($r['pdf_path'] ?? '') !== '', 'queued_at' => $r['queued_at'] !== null ? (string)$r['queued_at'] : null,
            'created_at' => (string)$r['created_at']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Quem pode ser responsável: quem tem a permissão de propostas (a mesma lista que recebe o aviso).
     *
     * @return list<array{id:int,name:string}>
     */
    public function team(): array
    {
        $team = array_map(static fn (User $u): array => ['id' => (int)$u->id, 'name' => trim((string)$u->first_name . ' ' . (string)$u->last_name)], ProposalService::proposalTeam());
        usort($team, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $team;
    }

    /**
     * Muda a situação e o responsável. Contatada pela primeira vez grava contacted_at. Entra no histórico.
     *
     * @return array<string,mixed> a proposta atualizada
     * @throws ProposalDeskError
     */
    public function update(int $id, string $status, ?int $assignedTo): array
    {
        $before = $this->find($id);
        if ($before === null) {
            throw new ProposalDeskError('not_found', 'Proposta não encontrada.', 404);
        }
        if (!isset(self::STATUSES[$status])) {
            throw new ProposalDeskError('validation_failed', 'Situação inválida.', 422, ['status' => 'Escolha uma das situações da lista.']);
        }
        if ($assignedTo !== null && !in_array($assignedTo, array_column($this->team(), 'id'), true)) {
            throw new ProposalDeskError('validation_failed', 'Responsável inválido.', 422, ['assigned_to' => 'Escolha alguém da equipe de propostas.']);
        }
        $contactedAt = $status === 'contacted' && $before['contacted_at'] === null ? date('Y-m-d H:i:s') : $before['contacted_at'];
        $stmt = $this->pdo()->prepare('UPDATE proposals SET status = :status, assigned_to = :assigned, contacted_at = :contacted WHERE id = :id');
        $stmt->execute([':status' => $status, ':assigned' => $assignedTo, ':contacted' => $contactedAt, ':id' => $id]);
        Audit::record('update', 'proposals', $id, ['status' => $before['status'], 'assigned_to' => $before['assigned_to']], ['status' => $status, 'assigned_to' => $assignedTo]);
        return $this->find($id) ?? $before;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function present(array $row): array
    {
        $status = (string)$row['status'];
        return [
            'id' => (int)$row['id'],
            'protocol' => (string)$row['protocol'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'whatsapp' => (string)$row['whatsapp'],
            'condominium' => (string)$row['condominium'],
            'units' => (int)$row['units'],
            'profile' => (string)$row['profile'],
            'profile_label' => self::PROFILES[(string)$row['profile']] ?? ucfirst((string)$row['profile']),
            'message' => $row['message'] !== null ? (string)$row['message'] : null,
            'status' => $status,
            'status_label' => self::label($status),
            'assigned_to' => $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null,
            'source_url' => $row['source_url'] !== null ? (string)$row['source_url'] : null,
            'origin' => (string)($row['origin'] ?? 'site'),
            'origin_label' => ProposalComposer::originLabel((string)($row['origin'] ?? 'site')),
            'created_by' => isset($row['created_by']) ? (int)$row['created_by'] : null,
            'quote' => ProposalComposer::quote((int)$row['units'], self::nullableInt($row['price_admin_unit_cents'] ?? null), self::nullableInt($row['price_syndic_unit_cents'] ?? null), self::nullableInt($row['min_monthly_cents'] ?? null)),
            'marketing_consent' => !empty($row['marketing_consent_at']),
            'contacted_at' => $row['contacted_at'] !== null ? (string)$row['contacted_at'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    private function pdo(): PDO
    {
        $this->pdo ??= Connect::getInstance();
        if ($this->pdo === null) {
            throw new \RuntimeException('Sem conexão com o banco de dados.');
        }
        return $this->pdo;
    }
}
