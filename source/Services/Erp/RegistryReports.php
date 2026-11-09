<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Support\AuditMask;

/**
 * Relatórios cadastrais e administrativos (ADR 0084), somente leitura:
 *
 * - reservations() AP-085, units() AP-086, people() AP-087 (dados pessoais mascarados),
 *   condominiums() AP-089, occurrences() AP-090, pendingPayments() AP-093, closings() AP-094,
 *   moves() AP-095, addresses() AP-096 (endereço dos condomínios), logs() AP-097 (auditoria do ERP,
 *   só com acesso a todos os condomínios), accessPeople() AP-098 e sentBoletos() AP-099 (avisos de
 *   boleto entregues pela fila, ADR 0055/0056).
 *
 * Escopo por condomínio (ADR-002); até MAX_ROWS linhas (acima disso, pede para reduzir o filtro).
 */
final class RegistryReports
{
    public const MAX_ROWS = 5000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Telefone mascarado (regra pura): só os 4 últimos dígitos aparecem. */
    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string)$phone) ?? '';
        return $digits === '' ? '' : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /** E-mail mascarado (regra pura), como na trilha da fila (ADR 0055); vazio ou inválido = ''. */
    public static function maskEmail(?string $email): string
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? NotificationDispatcher::mask($email) : '';
    }

    /** AP-085: reservas no período (data da reserva), por área, com a situação. */
    public function reservations(User $user, ?int $condominiumId, string $from, string $to, ?string $status = null): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        [$where, $params] = $this->scoped($user, $condominiumId, 'r');
        if ($where === null) {
            return $this->empty(['from' => $from, 'to' => $to]);
        }
        $where .= ' AND r.deleted_at IS NULL AND r.reserved_on BETWEEN :f AND :t';
        $params += ['f' => $from, 't' => $to];
        if (in_array($status, ['active', 'confirmed', 'inactive'], true)) {
            $where .= ' AND r.status = :s';
            $params['s'] = $status;
        }
        $rows = $this->fetch("SELECT r.id, c.name AS condominium_name, a.name AS area, u.label AS unit_label, r.resident_name, r.reserved_on, r.starts_time, r.ends_time,
                r.fee_cents, r.status FROM erp_reservations r JOIN condominiums c ON c.id = r.condominium_id JOIN erp_common_areas a ON a.id = r.common_area_id
                JOIN erp_units u ON u.id = r.unit_id WHERE {$where} ORDER BY r.reserved_on, r.starts_time, r.id", $params);
        $labels = ['active' => 'Aguardando aprovação', 'confirmed' => 'Confirmada', 'inactive' => 'Cancelada'];
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'area' => (string)$r['area'],
            'unit_label' => (string)$r['unit_label'], 'resident_name' => (string)$r['resident_name'], 'reserved_on' => (string)$r['reserved_on'],
            'starts_time' => $r['starts_time'] !== null ? substr((string)$r['starts_time'], 0, 5) : null, 'ends_time' => $r['ends_time'] !== null ? substr((string)$r['ends_time'], 0, 5) : null,
            'fee_cents' => $r['fee_cents'] !== null ? (int)$r['fee_cents'] : null, 'status' => (string)$r['status'], 'status_label' => $labels[$r['status']] ?? (string)$r['status']], $rows);
        return ['from' => $from, 'to' => $to, 'rows' => $data, 'totals' => ['reservations' => count($data),
            'confirmed' => count(array_filter($data, static fn (array $r): bool => $r['status'] === 'confirmed')),
            'fee_cents' => array_sum(array_map(static fn (array $r): int => $r['status'] !== 'inactive' ? (int)$r['fee_cents'] : 0, $data))]];
    }

    /** AP-086: unidades ativas com tipo, fração, área privativa e quantas pessoas, relógios e contas vencidas têm. */
    public function units(User $user, ?int $condominiumId): array
    {
        [$where, $params] = $this->scoped($user, $condominiumId, 'u');
        if ($where === null) {
            return $this->empty();
        }
        $rows = $this->fetch("SELECT u.id, c.name AS condominium_name, u.block, u.number, u.label, u.type, u.floor, u.ideal_fraction, u.private_area,
                (SELECT COUNT(*) FROM erp_residents r WHERE r.unit_id = u.id AND r.deleted_at IS NULL AND r.status = 'active') AS people,
                (SELECT COUNT(*) FROM erp_meters m WHERE m.unit_id = u.id AND m.deleted_at IS NULL AND m.status = 'active') AS meters,
                (SELECT COUNT(*) FROM erp_entries e WHERE e.unit_id = u.id AND e.nature = 'receita' AND e.status = 'active' AND e.deleted_at IS NULL
                    AND e.paid_on IS NULL AND e.due_on < CURDATE()) AS overdue
            FROM erp_units u JOIN condominiums c ON c.id = u.condominium_id WHERE {$where} AND u.deleted_at IS NULL AND u.status = 'active'
            ORDER BY c.name, u.label", $params);
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'block' => $r['block'],
            'number' => (string)$r['number'], 'unit_label' => (string)$r['label'], 'type' => (string)$r['type'], 'floor' => $r['floor'],
            'ideal_fraction' => $r['ideal_fraction'], 'private_area' => $r['private_area'], 'people' => (int)$r['people'], 'meters' => (int)$r['meters'],
            'overdue_entries' => (int)$r['overdue']], $rows);
        $sum = array_sum(array_map(static fn (array $r): float => (float)($r['ideal_fraction'] ?? 0), $data));
        return ['rows' => $data, 'totals' => ['units' => count($data), 'without_fraction' => count(array_filter($data, static fn (array $r): bool => $r['ideal_fraction'] === null)),
            'fraction_sum' => round($sum, 6), 'with_overdue' => count(array_filter($data, static fn (array $r): bool => $r['overdue_entries'] > 0))]];
    }

    /** AP-087: pessoas ativas por unidade e vínculo; documento, e-mail e telefone mascarados (exige erp.residents.view). */
    public function people(User $user, ?int $condominiumId, ?string $relationship = null): array
    {
        [$where, $params] = $this->scoped($user, $condominiumId, 'r');
        if ($where === null) {
            return $this->empty();
        }
        $where .= " AND r.deleted_at IS NULL AND r.status = 'active'";
        if (in_array($relationship, ['owner', 'tenant', 'resident', 'financial'], true)) {
            $where .= ' AND r.relationship = :rel';
            $params['rel'] = $relationship;
        }
        $rows = $this->fetch("SELECT r.id, c.name AS condominium_name, u.label AS unit_label, r.relationship, r.name, r.document, r.email, r.phone,
                r.billing_by_email, r.notices_opt_out, r.starts_on FROM erp_residents r JOIN condominiums c ON c.id = r.condominium_id
                LEFT JOIN erp_units u ON u.id = r.unit_id WHERE {$where} ORDER BY c.name, u.label, r.relationship = 'owner' DESC, r.name", $params);
        $labels = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'unit_label' => $r['unit_label'],
            'relationship' => (string)$r['relationship'], 'relationship_label' => $labels[$r['relationship']] ?? (string)$r['relationship'], 'name' => (string)$r['name'],
            'document' => CollectionReports::maskDocument($r['document']), 'email' => self::maskEmail($r['email']), 'phone' => self::maskPhone($r['phone']),
            'billing_by_email' => (bool)$r['billing_by_email'], 'notices' => !(bool)$r['notices_opt_out'], 'starts_on' => $r['starts_on']], $rows);
        return ['rows' => $data, 'totals' => ['people' => count($data)]];
    }

    /** AP-089: condomínios do escopo com o endereço, as unidades ativas, as pessoas e as contas bancárias. */
    public function condominiums(User $user): array
    {
        $ids = $this->ids($user, null);
        if ($ids === []) {
            return $this->empty();
        }
        $rows = $this->fetch("SELECT c.id, c.name, c.document, c.status, c.city, c.state,
                (SELECT COUNT(*) FROM erp_units u WHERE u.condominium_id = c.id AND u.deleted_at IS NULL AND u.status = 'active') AS units,
                (SELECT COUNT(*) FROM erp_residents r WHERE r.condominium_id = c.id AND r.deleted_at IS NULL AND r.status = 'active') AS people,
                (SELECT COUNT(*) FROM erp_bank_accounts b WHERE b.condominium_id = c.id AND b.deleted_at IS NULL AND b.status = 'active') AS banks
            FROM condominiums c WHERE c.id IN (" . implode(',', $ids) . ') ORDER BY c.name', []);
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'document' => (string)($r['document'] ?? ''),
            'status' => (string)$r['status'], 'city' => $r['city'], 'state' => $r['state'], 'units' => (int)$r['units'], 'people' => (int)$r['people'], 'bank_accounts' => (int)$r['banks']], $rows);
        return ['rows' => $data, 'totals' => ['condominiums' => count($data), 'units' => array_sum(array_column($data, 'units'))]];
    }

    /** AP-090: ocorrências no período (data da ocorrência), com prioridade, situação e o chamado. */
    public function occurrences(User $user, ?int $condominiumId, string $from, string $to, ?string $status = null): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        [$where, $params] = $this->scoped($user, $condominiumId, 'o');
        if ($where === null) {
            return $this->empty(['from' => $from, 'to' => $to]);
        }
        $where .= ' AND o.deleted_at IS NULL AND o.occurred_on BETWEEN :f AND :t';
        $params += ['f' => $from, 't' => $to];
        if (in_array($status, ['active', 'running', 'done'], true)) {
            $where .= ' AND o.status = :s';
            $params['s'] = $status;
        }
        $rows = $this->fetch("SELECT o.id, c.name AS condominium_name, u.label AS unit_label, o.subject, o.priority, o.occurred_on, o.status, o.ticket_id
            FROM erp_occurrences o JOIN condominiums c ON c.id = o.condominium_id LEFT JOIN erp_units u ON u.id = o.unit_id WHERE {$where}
            ORDER BY o.occurred_on DESC, o.id DESC", $params);
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'unit_label' => $r['unit_label'],
            'subject' => (string)$r['subject'], 'priority' => (string)$r['priority'], 'occurred_on' => (string)$r['occurred_on'], 'status' => (string)$r['status'],
            'ticket_id' => $r['ticket_id'] !== null ? (int)$r['ticket_id'] : null], $rows);
        $byStatus = [];
        foreach ($data as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
        }
        return ['from' => $from, 'to' => $to, 'rows' => $data, 'totals' => ['occurrences' => count($data), 'by_status' => $byStatus]];
    }

    /** AP-093: contas a pagar em aberto até a data (padrão: hoje + 30 dias), com o fornecedor e se venceram. */
    public function pendingPayments(User $user, ?int $condominiumId, ?string $until = null): array
    {
        $until ??= date('Y-m-d', strtotime('+30 days'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
        if ($date === false || $date->format('Y-m-d') !== $until) {
            throw new InvalidArgumentException('Data limite inválida (AAAA-MM-DD).');
        }
        [$where, $params] = $this->scoped($user, $condominiumId, 'e');
        if ($where === null) {
            return $this->empty(['until' => $until]);
        }
        $rows = $this->fetch("SELECT e.id, c.name AS condominium_name, e.description, e.due_on, e.amount_cents, a.code, a.name AS account, s.legal_name AS supplier, e.document_number
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id JOIN erp_chart_accounts a ON a.id = e.chart_account_id LEFT JOIN erp_suppliers s ON s.id = e.supplier_id
            WHERE {$where} AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.paid_on IS NULL AND e.due_on <= :u
            ORDER BY e.due_on, e.id", $params + ['u' => $until]);
        $today = date('Y-m-d');
        $data = array_map(static fn (array $r): array => ['entry_id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'description' => (string)$r['description'],
            'supplier' => $r['supplier'], 'code' => (string)$r['code'], 'account' => (string)$r['account'], 'document_number' => $r['document_number'],
            'due_on' => (string)$r['due_on'], 'overdue' => (string)$r['due_on'] < $today, 'amount_cents' => (int)$r['amount_cents']], $rows);
        return ['until' => $until, 'rows' => $data, 'totals' => ['entries' => count($data), 'amount_cents' => array_sum(array_column($data, 'amount_cents')),
            'overdue_cents' => array_sum(array_map(static fn (array $r): int => $r['overdue'] ? $r['amount_cents'] : 0, $data))]];
    }

    /** AP-094: fechamentos mensais (prestações de contas) na faixa de competência. */
    public function closings(User $user, ?int $condominiumId, string $from, string $to): array
    {
        [$from, $to] = ManagementReports::competences($from, $to);
        [$where, $params] = $this->scoped($user, $condominiumId, 'm');
        if ($where === null) {
            return $this->empty(['from' => $from, 'to' => $to]);
        }
        $rows = $this->fetch("SELECT m.id, c.name AS condominium_name, m.competence, m.status, m.revenue_cents, m.expense_cents, m.open_revenue_cents, m.open_expense_cents,
                m.closed_at, m.reopened_at, m.reopen_reason FROM erp_month_closings m JOIN condominiums c ON c.id = m.condominium_id
            WHERE {$where} AND m.competence BETWEEN :f AND :t ORDER BY c.name, m.competence", $params + ['f' => $from, 't' => $to]);
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'competence' => (string)$r['competence'],
            'status' => (string)$r['status'], 'revenue_cents' => (int)$r['revenue_cents'], 'expense_cents' => (int)$r['expense_cents'],
            'result_cents' => (int)$r['revenue_cents'] - (int)$r['expense_cents'], 'open_revenue_cents' => (int)$r['open_revenue_cents'],
            'open_expense_cents' => (int)$r['open_expense_cents'], 'closed_at' => $r['closed_at'], 'reopened_at' => $r['reopened_at'], 'reopen_reason' => $r['reopen_reason']], $rows);
        return ['from' => $from, 'to' => $to, 'rows' => $data, 'totals' => ['closings' => count($data)]];
    }

    /** AP-095: mudanças agendadas no período. */
    public function moves(User $user, ?int $condominiumId, string $from, string $to): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        [$where, $params] = $this->scoped($user, $condominiumId, 'm');
        if ($where === null) {
            return $this->empty(['from' => $from, 'to' => $to]);
        }
        $rows = $this->fetch("SELECT m.id, c.name AS condominium_name, u.label AS unit_label, m.resident_name, m.kind, m.scheduled_on, m.period, m.status
            FROM erp_moves m JOIN condominiums c ON c.id = m.condominium_id JOIN erp_units u ON u.id = m.unit_id
            WHERE {$where} AND m.deleted_at IS NULL AND m.scheduled_on BETWEEN :f AND :t ORDER BY m.scheduled_on, m.id", $params + ['f' => $from, 't' => $to]);
        $data = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'unit_label' => (string)$r['unit_label'],
            'resident_name' => (string)$r['resident_name'], 'kind' => (string)$r['kind'], 'scheduled_on' => (string)$r['scheduled_on'], 'period' => $r['period'],
            'status' => (string)$r['status']], $rows);
        return ['from' => $from, 'to' => $to, 'rows' => $data, 'totals' => ['moves' => count($data)]];
    }

    /** AP-096: endereço e contato de cada condomínio do escopo (dados da administração, não de pessoas). */
    public function addresses(User $user): array
    {
        $ids = $this->ids($user, null);
        if ($ids === []) {
            return $this->empty();
        }
        $rows = $this->fetch('SELECT id, name, zip_code, street, number, complement, district, city, state, phone, email FROM condominiums
            WHERE id IN (' . implode(',', $ids) . ') ORDER BY name', []);
        return ['rows' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'zip_code' => $r['zip_code'], 'street' => $r['street'],
            'number' => $r['number'], 'complement' => $r['complement'], 'district' => $r['district'], 'city' => $r['city'], 'state' => $r['state'],
            'phone' => $r['phone'], 'email' => $r['email']], $rows), 'totals' => ['condominiums' => count($rows)]];
    }

    /**
     * AP-097: logs da auditoria do ERP (entidades erp_*) no período. Os logs não têm condomínio: só quem
     * acessa todos os condomínios vê. O contexto vem cortado em 500 caracteres.
     */
    public function logs(User $user, string $from, string $to, ?string $entity = null, ?string $action = null): array
    {
        if (!$this->scope->hasGlobalAccess($user)) {
            throw new RegistryForbidden();
        }
        [$from, $to] = CollectionReports::period($from, $to);
        $where = "l.entity LIKE 'erp\\_%' AND l.created_at BETWEEN :f AND :t";
        $params = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
        if (is_string($entity) && preg_match('/^erp_[a-z_]{1,60}$/', $entity)) {
            $where .= ' AND l.entity = :e';
            $params['e'] = $entity;
        }
        if (in_array($action, ['create', 'update', 'delete', 'export'], true)) {
            $where .= ' AND l.action = :a';
            $params['a'] = $action;
        }
        $rows = $this->fetch("SELECT l.id, l.created_at, l.action, l.entity, l.entity_id, l.severity, l.ip, LEFT(l.context_json, 500) AS context,
                CONCAT_WS(' ', u.first_name, u.last_name) AS user_name FROM system_audit_logs l LEFT JOIN users u ON u.id = l.users_id
            WHERE {$where} ORDER BY l.id DESC", $params);
        return ['from' => $from, 'to' => $to, 'rows' => array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'created_at' => (string)$r['created_at'],
            'user_name' => trim((string)$r['user_name']) !== '' ? (string)$r['user_name'] : 'Sistema', 'action' => (string)$r['action'], 'entity' => (string)$r['entity'],
            'entity_id' => $r['entity_id'], 'severity' => (string)$r['severity'], 'ip' => self::maskIp($r['ip']), 'context' => AuditMask::text((string)($r['context'] ?? ''))], $rows),
            'totals' => ['logs' => count($rows)]];
    }

    /** IP mascarado (regra pura, ADR 0096): IPv4 sem o último octeto, IPv6 só com os 3 primeiros grupos. */
    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string)preg_replace('/\.\d+$/', '.*', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':*';
        }
        return '*';
    }

    /** AP-098: quem tem acesso a cada condomínio do escopo (concessão explícita), com o e-mail mascarado. */
    public function accessPeople(User $user, ?int $condominiumId): array
    {
        [$where, $params] = $this->scoped($user, $condominiumId, 'a');
        if ($where === null) {
            return $this->empty();
        }
        $rows = $this->fetch("SELECT a.condominium_id, c.name AS condominium_name, u.id AS user_id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email, u.status, a.granted_at
            FROM user_condominium_access a JOIN condominiums c ON c.id = a.condominium_id JOIN users u ON u.id = a.user_id
            WHERE {$where} ORDER BY c.name, name", $params);
        return ['rows' => array_map(static fn (array $r): array => ['condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
            'user_id' => (int)$r['user_id'], 'name' => (string)$r['name'], 'email' => self::maskEmail($r['email']), 'status' => (string)$r['status'],
            'granted_at' => $r['granted_at']], $rows), 'totals' => ['grants' => count($rows), 'people' => count(array_unique(array_column($rows, 'user_id')))],
            'note' => 'Quem acessa todos os condomínios (permissão global) não aparece aqui, porque não tem concessão por condomínio.'];
    }

    /** AP-099: avisos de boleto (bank_charge.issued) da fila no período, com o modo de entrega e quantos destinatários. */
    public function sentBoletos(User $user, ?int $condominiumId, string $from, string $to): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        [$where, $params] = $this->scoped($user, $condominiumId, 'o');
        if ($where === null) {
            return $this->empty(['from' => $from, 'to' => $to]);
        }
        $rows = $this->fetch("SELECT o.id, c.name AS condominium_name, o.status, o.delivery_mode, o.recipients_count, o.processed_at, o.created_at, ch.our_number, ch.amount_cents,
                ch.due_on, u.label AS unit_label
            FROM erp_notification_outbox o JOIN condominiums c ON c.id = o.condominium_id
            LEFT JOIN erp_bank_charges ch ON ch.id = o.subject_id LEFT JOIN erp_entries e ON e.id = ch.entry_id LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE {$where} AND o.event_type = 'bank_charge.issued' AND o.created_at BETWEEN :f AND :t ORDER BY o.created_at DESC, o.id DESC",
            $params + ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $data = array_map(static fn (array $r): array => ['outbox_id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'unit_label' => $r['unit_label'],
            'our_number' => $r['our_number'], 'amount_cents' => $r['amount_cents'] !== null ? (int)$r['amount_cents'] : null, 'due_on' => $r['due_on'],
            'status' => (string)$r['status'], 'delivery_mode' => $r['delivery_mode'], 'recipients' => (int)$r['recipients_count'], 'processed_at' => $r['processed_at']], $rows);
        $byStatus = [];
        foreach ($data as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
        }
        return ['from' => $from, 'to' => $to, 'rows' => $data, 'totals' => ['notices' => count($data), 'by_status' => $byStatus,
            'recipients' => array_sum(array_column($data, 'recipients'))]];
    }

    /**
     * Tabela para exportar (regra pura), com as células saneadas por CollectionReports::csvCell.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(string $kind, array $report): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $date = static fn (mixed $d): string => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" . (strlen($d) > 10 ? substr($d, 10, 6) : '') : (string)($d ?? '');
        $bool = static fn (mixed $b): string => $b ? 'sim' : 'não';
        $columns = match ($kind) {
            'reservations' => ['Condomínio' => 'condominium_name', 'Área' => 'area', 'Unidade' => 'unit_label', 'Morador' => 'resident_name', 'Data' => ['reserved_on', $date],
                'Das' => 'starts_time', 'Até' => 'ends_time', 'Taxa' => ['fee_cents', $money], 'Situação' => 'status_label'],
            'units' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Tipo' => 'type', 'Andar' => 'floor', 'Fração ideal' => 'ideal_fraction',
                'Área privativa' => 'private_area', 'Pessoas' => 'people', 'Relógios' => 'meters', 'Contas vencidas' => 'overdue_entries'],
            'people' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Vínculo' => 'relationship_label', 'Nome' => 'name', 'Documento' => 'document',
                'E-mail' => 'email', 'Telefone' => 'phone', 'Cobrança por e-mail' => ['billing_by_email', $bool], 'Comunicados' => ['notices', $bool]],
            'condominiums' => ['Condomínio' => 'name', 'CNPJ' => 'document', 'Cidade' => 'city', 'UF' => 'state', 'Situação' => 'status', 'Unidades' => 'units',
                'Pessoas' => 'people', 'Contas bancárias' => 'bank_accounts'],
            'occurrences' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Assunto' => 'subject', 'Prioridade' => 'priority', 'Data' => ['occurred_on', $date],
                'Situação' => 'status', 'Chamado' => 'ticket_id'],
            'pending-payments' => ['Condomínio' => 'condominium_name', 'Descrição' => 'description', 'Fornecedor' => 'supplier', 'Conta' => 'account', 'Documento' => 'document_number',
                'Vencimento' => ['due_on', $date], 'Vencida' => ['overdue', $bool], 'Valor' => ['amount_cents', $money]],
            'closings' => ['Condomínio' => 'condominium_name', 'Competência' => 'competence', 'Situação' => 'status', 'Receitas' => ['revenue_cents', $money],
                'Despesas' => ['expense_cents', $money], 'Resultado' => ['result_cents', $money], 'Fechado em' => ['closed_at', $date], 'Reaberto em' => ['reopened_at', $date]],
            'moves' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Morador' => 'resident_name', 'Tipo' => 'kind', 'Data' => ['scheduled_on', $date],
                'Período' => 'period', 'Situação' => 'status'],
            'addresses' => ['Condomínio' => 'name', 'CEP' => 'zip_code', 'Logradouro' => 'street', 'Número' => 'number', 'Complemento' => 'complement', 'Bairro' => 'district',
                'Cidade' => 'city', 'UF' => 'state', 'Telefone' => 'phone', 'E-mail' => 'email'],
            'logs' => ['Data' => ['created_at', $date], 'Usuário' => 'user_name', 'Ação' => 'action', 'Entidade' => 'entity', 'Registro' => 'entity_id', 'Severidade' => 'severity',
                'IP' => 'ip', 'Contexto' => 'context'],
            'access-people' => ['Condomínio' => 'condominium_name', 'Nome' => 'name', 'E-mail' => 'email', 'Situação' => 'status', 'Concedido em' => ['granted_at', $date]],
            'sent-boletos' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Nosso número' => 'our_number', 'Valor' => ['amount_cents', $money],
                'Vencimento' => ['due_on', $date], 'Situação' => 'status', 'Entrega' => 'delivery_mode', 'Destinatários' => 'recipients', 'Processado em' => ['processed_at', $date]],
            default => throw new InvalidArgumentException('Relatório sem exportação.'),
        };
        $rows = [];
        foreach ($report['rows'] ?? [] as $row) {
            $line = [];
            foreach ($columns as $spec) {
                [$field, $format] = is_array($spec) ? $spec : [$spec, null];
                $value = $row[$field] ?? null;
                $line[] = CollectionReports::csvCell($format !== null && $value !== null ? $format($value) : (string)($value ?? ''));
            }
            $rows[] = $line;
        }
        return ['header' => array_keys($columns), 'rows' => $rows];
    }

    /** @return array{0:?string, 1:array} filtro de escopo pela coluna condominium_id de $alias (null = nada no escopo) */
    private function scoped(User $user, ?int $condominiumId, string $alias): array
    {
        $ids = $this->ids($user, $condominiumId);
        return $ids === [] ? [null, []] : ["{$alias}.condominium_id IN (" . implode(',', $ids) . ')', []];
    }

    private function fetch(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql . ' LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d linhas: reduza o período ou escolha um condomínio.', self::MAX_ROWS));
        }
        return $rows;
    }

    private function empty(array $extra = []): array
    {
        return $extra + ['rows' => [], 'totals' => []];
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
            return [$condominiumId];
        }
        return array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
    }
}
