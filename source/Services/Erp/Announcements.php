<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Comunicação pela outbox (ADR 0059). Nada é enviado aqui: cada unidade vira um evento na outbox
 * (ADR 0054), e a fila do ADR 0055 entrega, só em log ou caixa de teste fora de produção.
 *
 * - announce() (AP-111): comunicado geral para todas as unidades ativas ou as escolhidas. Recebem as
 *   pessoas ativas com e-mail que não recusaram comunicado (notices_opt_out).
 * - collection() (AP-137): aviso de cobrança para as unidades com conta vencida em aberto
 *   (Agreements::delinquency), só para quem autorizou a cobrança por e-mail (billing_by_email). Unidade
 *   avisada nos últimos COLLECTION_COOLDOWN_DAYS dias fica de fora (sem aviso repetido).
 * - send_at (AP-138): agora ou agendado, até MAX_SCHEDULE_DAYS dias; vira o available_at dos eventos.
 *   cancel() cancela o que ainda não saiu.
 * - addresses() (AP-139): quem recebe o quê em cada unidade, com o e-mail mascarado.
 *
 * Escopo por condomínio (ADR-002); tudo auditado.
 */
final class Announcements
{
    public const MAX_SCHEDULE_DAYS = 60;
    public const COLLECTION_COOLDOWN_DAYS = 7;
    public const MAX_BODY = 5000;
    /** Teto de unidades escolhidas num lote (comunicado e aviso de cobrança). */
    public const MAX_UNITS = 2000;
    /** Teto de comunicados e avisos criados por condomínio em 24 horas (ADR 0065). */
    public const MAX_PER_DAY = 20;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Data do envio (regra pura): vazio = agora; "AAAA-MM-DD HH:MM" ou "AAAA-MM-DDTHH:MM" entre agora
     * (1 minuto de folga) e MAX_SCHEDULE_DAYS dias.
     */
    public static function sendAt(mixed $value, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        if ($value === null || $value === '') {
            return $now->format('Y-m-d H:i:s');
        }
        $text = is_string($value) ? str_replace('T', ' ', trim($value)) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($text, 0, 16));
        if ($date === false || $date->format('Y-m-d H:i') !== substr($text, 0, 16)) {
            throw new InvalidArgumentException('Data de envio inválida (AAAA-MM-DD HH:MM).');
        }
        if ($date < $now->modify('-1 minute')) {
            throw new InvalidArgumentException('A data de envio não pode estar no passado.');
        }
        if ($date > $now->modify('+' . self::MAX_SCHEDULE_DAYS . ' days')) {
            throw new InvalidArgumentException(sprintf('Agende com até %d dias de antecedência.', self::MAX_SCHEDULE_DAYS));
        }
        return $date->format('Y-m-d H:i:s');
    }

    /** Texto limpo (regra pura): espaços normalizados por linha, sem caracteres de controle. */
    public static function text(mixed $value, int $min, int $max, string $what): string
    {
        $text = is_string($value) ? (string)preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", $value)) : '';
        $text = trim((string)preg_replace('/[ \t]+/u', ' ', $text));
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw new InvalidArgumentException("Informe {$what} ({$min} a {$max} caracteres).");
        }
        return $text;
    }

    /** Linha única (regra pura): como text(), mas quebras de linha viram espaço. Para assunto e motivo (ADR 0065). */
    public static function line(mixed $value, int $min, int $max, string $what): string
    {
        return self::text(is_string($value) ? (string)preg_replace('/[\r\n\x{2028}\x{2029}]+/u', ' ', $value) : $value, $min, $max, $what);
    }

    /**
     * Unidades escolhidas (regra pura): null quando não informadas; senão ids positivos, sem repetição,
     * de 1 a MAX_UNITS (ADR 0065).
     *
     * @return ?list<int>
     */
    public static function unitIds(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || count($raw) > self::MAX_UNITS) {
            throw new InvalidArgumentException(sprintf('Escolha de 1 a %d unidades.', self::MAX_UNITS));
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException('Unidade escolhida inválida.');
            }
            $ids[(int)$id] = (int)$id;
        }
        if ($ids === []) {
            throw new InvalidArgumentException(sprintf('Escolha de 1 a %d unidades.', self::MAX_UNITS));
        }
        return array_values($ids);
    }

    /**
     * @param array{condominium_id?:mixed, subject?:mixed, body?:mixed, audience?:mixed, unit_ids?:mixed, send_at?:mixed} $input
     */
    public function announce(User $user, array $input): array
    {
        $condominium = $this->condominium($user, $input['condominium_id'] ?? null);
        $subject = self::line($input['subject'] ?? null, 5, 120, 'o assunto');
        $body = self::text($input['body'] ?? null, 10, self::MAX_BODY, 'o texto do comunicado');
        $sendAt = self::sendAt($input['send_at'] ?? null);
        $audience = ($input['audience'] ?? 'all') === 'units' ? 'units' : 'all';
        $units = $this->units($condominium, $audience === 'units' ? (self::unitIds($input['unit_ids'] ?? []) ?? []) : null);
        if ($units === []) {
            throw new InvalidArgumentException('Não há unidades ativas para o comunicado.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lock($condominium);
            $id = $this->insert($user, $condominium, 'general', $subject, $body, $audience, $sendAt, count($units));
            $outbox = new NotificationOutbox($this->pdo);
            foreach ($units as $unit) {
                $outbox->record('announcement.published', "announcement:{$id}:{$unit['id']}", $condominium, 'erp_announcements', $id,
                    ['announcement_id' => $id, 'unit_id' => (int)$unit['id'], 'unit_label' => (string)$unit['label']], (int)$user->id, $sendAt);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_announcements', $id, null, ['kind' => 'general', 'subject' => $subject, 'units' => count($units), 'send_at' => $sendAt]);
        return $this->find($user, $id);
    }

    /**
     * Aviso de cobrança (AP-137) para as unidades com conta vencida há pelo menos min_days_late dias
     * na data as_of (padrão hoje). Unidades escolhidas (unit_ids) restringem o lote.
     *
     * @param array{condominium_id?:mixed, as_of?:mixed, min_days_late?:mixed, unit_ids?:mixed, send_at?:mixed} $input
     */
    public function collection(User $user, array $input): array
    {
        $condominium = $this->condominium($user, $input['condominium_id'] ?? null);
        $asOf = is_string($input['as_of'] ?? null) && $input['as_of'] !== '' ? $input['as_of'] : date('Y-m-d');
        if ($asOf > date('Y-m-d')) {
            throw new InvalidArgumentException('A data de referência não pode ser futura.');
        }
        $minDays = filter_var($input['min_days_late'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($minDays === false) {
            throw new InvalidArgumentException('Dias mínimos de atraso inválidos (1 a 3650).');
        }
        $sendAt = self::sendAt($input['send_at'] ?? null);
        $only = self::unitIds($input['unit_ids'] ?? null);
        $only = $only === null ? null : array_fill_keys($only, true);
        $delinquency = (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominium, $asOf);
        $this->pdo->beginTransaction();
        try {
            // Trava o condomínio antes de ler os avisos recentes: dois pedidos ao mesmo tempo não avisam
            // a mesma unidade duas vezes (ADR 0065).
            $this->lock($condominium);
            $recent = $this->recentlyReminded($condominium);
            $targets = [];
            $skipped = 0;
            foreach ($delinquency['units'] as $unit) {
                if ($unit['unit_id'] === null || (int)$unit['max_days_late'] < $minDays || ($only !== null && !isset($only[(int)$unit['unit_id']]))) {
                    continue;
                }
                if (isset($recent[(int)$unit['unit_id']])) {
                    $skipped++;
                    continue;
                }
                $targets[] = $unit;
            }
            if ($targets === []) {
                throw new DomainException($skipped > 0
                    ? sprintf('As %d unidade(s) em atraso já foram avisadas nos últimos %d dias.', $skipped, self::COLLECTION_COOLDOWN_DAYS)
                    : 'Nenhuma unidade com conta vencida nesse critério.');
            }
            $id = $this->insert($user, $condominium, 'collection', 'Aviso de débitos em aberto', null, $only === null ? 'all' : 'units', $sendAt, count($targets));
            $outbox = new NotificationOutbox($this->pdo);
            foreach ($targets as $unit) {
                $outbox->record('collection.reminder', "collection:{$id}:{$unit['unit_id']}", $condominium, 'erp_announcements', $id, [
                    'announcement_id' => $id, 'unit_id' => (int)$unit['unit_id'], 'unit_label' => (string)$unit['unit_label'],
                    'entries' => count($unit['entries']), 'amount_cents' => (int)$unit['amount_cents'], 'oldest_due_on' => (string)$unit['oldest_due_on'], 'as_of' => $asOf,
                ], (int)$user->id, $sendAt);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('create', 'erp_announcements', $id, null, ['kind' => 'collection', 'units' => count($targets), 'skipped' => $skipped, 'as_of' => $asOf, 'send_at' => $sendAt]);
        return $this->find($user, $id) + ['skipped_recent' => $skipped];
    }

    /** Cancela o comunicado e os avisos que ainda não saíram; os já enviados ficam como estão. */
    public function cancel(User $user, int $id, mixed $reason): array
    {
        $reason = self::line($reason, 5, 255, 'o motivo do cancelamento');
        $row = $this->row($user, $id);
        if ($row['status'] !== 'active') {
            throw new DomainException('O comunicado já está cancelado.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'cancelled', last_error = 'Comunicado cancelado.', processed_at = NOW()
                WHERE subject_table = 'erp_announcements' AND subject_id = :id AND status = 'pending'");
            $stmt->execute(['id' => $id]);
            $stopped = $stmt->rowCount();
            $this->pdo->prepare("UPDATE erp_announcements SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r WHERE id = :id AND status = 'active'")
                ->execute(['u' => (int)$user->id, 'r' => $reason, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_announcements', $id, ['status' => 'active'], ['status' => 'cancelled', 'cancel_reason' => $reason, 'stopped' => $stopped]);
        return $this->find($user, $id) + ['stopped' => $stopped];
    }

    /**
     * @param array{kind?:mixed, scheduled?:mixed} $filters scheduled = só os que ainda vão sair (AP-138)
     * @return array{data:list<array>, total:int}
     */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'total' => 0];
        }
        $where = 'a.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (in_array($filters['kind'] ?? null, ['general', 'collection'], true)) {
            $where .= ' AND a.kind = :k';
            $params['k'] = $filters['kind'];
        }
        if (filter_var($filters['scheduled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $where .= " AND a.status = 'active' AND a.send_at > NOW()";
        }
        $stmt = $this->pdo->prepare("SELECT a.*, c.name AS condominium_name FROM erp_announcements a JOIN condominiums c ON c.id = a.condominium_id
            WHERE {$where} ORDER BY a.send_at DESC, a.id DESC LIMIT 200");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats = $this->stats(array_map(static fn (array $r): int => (int)$r['id'], $rows));
        $data = array_map(fn (array $r): array => $this->present($r, $stats[(int)$r['id']] ?? []), $rows);
        return ['data' => $data, 'total' => count($data)];
    }

    public function find(User $user, int $id): array
    {
        $row = $this->row($user, $id);
        return $this->present($row, $this->stats([$id])[$id] ?? [], true);
    }

    /**
     * Endereços de comunicação (AP-139): por unidade ativa, as pessoas ativas com o e-mail mascarado e
     * se recebem comunicado e cobrança. Unidade sem ninguém para comunicado aparece com aviso.
     */
    public function addresses(User $user, ?int $condominiumId): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return ['data' => [], 'totals' => ['units' => 0, 'without_notices' => 0, 'without_billing' => 0]];
        }
        $rows = $this->pdo->query("SELECT u.id AS unit_id, u.label, u.condominium_id, c.name AS condominium_name, r.id AS resident_id, r.name, r.relationship,
                r.email, r.billing_by_email, r.notices_opt_out
            FROM erp_units u JOIN condominiums c ON c.id = u.condominium_id
            LEFT JOIN erp_residents r ON r.unit_id = u.id AND r.deleted_at IS NULL AND r.status = 'active'
            WHERE u.condominium_id IN (" . implode(',', $ids) . ") AND u.deleted_at IS NULL AND u.status = 'active'
            ORDER BY c.name, u.label, r.relationship = 'owner' DESC, r.id")->fetchAll(PDO::FETCH_ASSOC);
        $units = [];
        foreach ($rows as $row) {
            $key = (int)$row['unit_id'];
            $units[$key] ??= ['unit_id' => $key, 'unit_label' => (string)$row['label'], 'condominium_id' => (int)$row['condominium_id'],
                'condominium_name' => (string)$row['condominium_name'], 'people' => [], 'notices' => 0, 'billing' => 0];
            if ($row['resident_id'] === null) {
                continue;
            }
            $valid = is_string($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false;
            $notices = $valid && (int)$row['notices_opt_out'] === 0;
            $billing = $valid && (int)$row['billing_by_email'] === 1;
            $units[$key]['people'][] = ['resident_id' => (int)$row['resident_id'], 'name' => (string)$row['name'], 'relationship' => (string)$row['relationship'],
                'email' => $valid ? NotificationDispatcher::mask((string)$row['email']) : null, 'valid_email' => $valid, 'notices' => $notices, 'billing' => $billing];
            $units[$key]['notices'] += $notices ? 1 : 0;
            $units[$key]['billing'] += $billing ? 1 : 0;
        }
        $data = array_values($units);
        return ['data' => $data, 'totals' => ['units' => count($data),
            'without_notices' => count(array_filter($data, static fn (array $u): bool => $u['notices'] === 0)),
            'without_billing' => count(array_filter($data, static fn (array $u): bool => $u['billing'] === 0))]];
    }

    /**
     * Unidades avisadas de cobrança nos últimos COLLECTION_COOLDOWN_DAYS dias, que o próximo aviso deixa
     * de fora. A tela AP-137 marca essas unidades antes do envio (ADR 0075).
     *
     * @return array<int, true>
     */
    public function remindedUnits(User $user, int $condominiumId): array
    {
        return $this->recentlyReminded($this->condominium($user, $condominiumId));
    }

    /**
     * Trava o condomínio até o fim da transação e aplica o teto de MAX_PER_DAY comunicados e avisos
     * criados em 24 horas (ADR 0065): um clique repetido ou um script não enchem a fila.
     */
    private function lock(int $condominium): void
    {
        $this->pdo->prepare('SELECT id FROM condominiums WHERE id = :c FOR UPDATE')->execute(['c' => $condominium]);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM erp_announcements WHERE condominium_id = :c AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $stmt->execute(['c' => $condominium]);
        if ((int)$stmt->fetchColumn() >= self::MAX_PER_DAY) {
            throw new DomainException(sprintf('Limite de %d comunicados e avisos por condomínio em 24 horas. Tente mais tarde.', self::MAX_PER_DAY));
        }
    }

    private function insert(User $user, int $condominium, string $kind, string $subject, ?string $body, string $audience, string $sendAt, int $units): int
    {
        $this->pdo->prepare('INSERT INTO erp_announcements (condominium_id, kind, subject, body, audience, send_at, units_count, created_by)
            VALUES (:c, :k, :s, :b, :a, :d, :n, :u)')->execute(['c' => $condominium, 'k' => $kind, 's' => $subject, 'b' => $body, 'a' => $audience,
            'd' => $sendAt, 'n' => $units, 'u' => (int)$user->id]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array{id:int, label:string}> unidades ativas do condomínio (todas ou as escolhidas) */
    private function units(int $condominium, mixed $only): array
    {
        $sql = "SELECT id, label FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'";
        if ($only !== null) {
            $ids = array_map('intval', (array)$only);
            if ($ids === []) {
                throw new InvalidArgumentException(sprintf('Escolha de 1 a %d unidades.', self::MAX_UNITS));
            }
            $sql .= ' AND id IN (' . implode(',', $ids) . ')';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY label, id');
        $stmt->execute(['c' => $condominium]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($only !== null && count($units) !== count((array)$only)) {
            throw new InvalidArgumentException('Alguma unidade escolhida não existe ativa neste condomínio.');
        }
        return $units;
    }

    /** @return array<int, true> unidades com aviso de cobrança ativo nos últimos COLLECTION_COOLDOWN_DAYS dias */
    private function recentlyReminded(int $condominium): array
    {
        $stmt = $this->pdo->prepare("SELECT o.payload_json FROM erp_notification_outbox o JOIN erp_announcements a ON a.id = o.subject_id
            WHERE o.subject_table = 'erp_announcements' AND o.event_type = 'collection.reminder' AND a.condominium_id = :c AND a.status = 'active'
              AND a.send_at >= DATE_SUB(NOW(), INTERVAL " . self::COLLECTION_COOLDOWN_DAYS . " DAY) AND o.status <> 'cancelled'");
        $stmt->execute(['c' => $condominium]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $unit = (int)((json_decode((string)$json, true) ?: [])['unit_id'] ?? 0);
            if ($unit > 0) {
                $out[$unit] = true;
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, array<string, int>> contagem dos avisos por situação */
    private function stats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->pdo->query("SELECT subject_id, status, COUNT(*) AS n, COALESCE(SUM(recipients_count), 0) AS people FROM erp_notification_outbox
            WHERE subject_table = 'erp_announcements' AND subject_id IN (" . implode(',', array_map('intval', $ids)) . ') GROUP BY subject_id, status')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['subject_id']][(string)$row['status']] = (int)$row['n'];
            $out[(int)$row['subject_id']]['recipients'] = ($out[(int)$row['subject_id']]['recipients'] ?? 0) + (int)$row['people'];
        }
        return $out;
    }

    private function condominium(User $user, mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !$this->scope->canAccess($user, (int)$id)) {
            throw new RegistryNotFound();
        }
        return (int)$id;
    }

    private function row(User $user, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT a.*, c.name AS condominium_name FROM erp_announcements a JOIN condominiums c ON c.id = a.condominium_id WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $row;
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $i): bool => $i > 0));
        if ($condominiumId !== null) {
            return in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        return $ids;
    }

    private function present(array $r, array $stats, bool $withBody = false): array
    {
        $out = [
            'id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => $r['condominium_name'] ?? null,
            'kind' => (string)$r['kind'], 'subject' => (string)$r['subject'], 'audience' => (string)$r['audience'], 'send_at' => (string)$r['send_at'],
            'scheduled' => $r['status'] === 'active' && (string)$r['send_at'] > date('Y-m-d H:i:s'), 'units_count' => (int)$r['units_count'],
            'status' => (string)$r['status'], 'created_at' => (string)$r['created_at'], 'cancelled_at' => $r['cancelled_at'], 'cancel_reason' => $r['cancel_reason'],
            'delivery' => ['pending' => $stats['pending'] ?? 0, 'processing' => $stats['processing'] ?? 0, 'sent' => $stats['sent'] ?? 0,
                'failed' => $stats['failed'] ?? 0, 'cancelled' => $stats['cancelled'] ?? 0, 'recipients' => $stats['recipients'] ?? 0],
        ];
        if ($withBody) {
            $out['body'] = $r['body'];
        }
        return $out;
    }
}
