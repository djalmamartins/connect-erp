<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Dashboard com dados reais (ADR 0157): tudo o que era demonstração na AP-001 passa a vir do MariaDB,
 * no condomínio da barra superior ou em todos do escopo do usuário (ADR-002).
 *
 * - finance(): receitas e despesas dos últimos 6 meses (competência, lançado e realizado), o mês
 *   escolhido como no balancete, inadimplência por faixa de atraso e o quadro por condomínio.
 *   Só para quem tem erp.entries.view (o controller confere).
 * - agenda(): próximos 30 dias de assembleias, manutenções e reservas.
 * - activities(): últimas ações da equipe (system_audit_logs) nos módulos do ERP, filtradas pelo
 *   condomínio do registro. Só para quem tem erp.audit.view. Sem dados pessoais: módulo, ação, número
 *   do registro, condomínio e o nome de quem fez.
 * Somente leitura; nada aqui grava.
 */
final class FinancialDashboard
{
    public const MONTHS = 6;
    public const AGENDA_DAYS = 30;
    public const AGENDA_LIMIT = 8;
    public const ACTIVITY_LIMIT = 6;
    public const ACTIVITY_SCAN = 300;

    /** Limites da situação de cada condomínio pela inadimplência do mês (em %). */
    public const ATTENTION_RATE = 5.0;
    public const CRITICAL_RATE = 15.0;

    /** Faixas de atraso, iguais às do relatório de inadimplentes (Agreements::bucket). */
    public const BUCKETS = ['1-30' => 'Até 30 dias', '31-60' => '31 a 60 dias', '61-90' => '61 a 90 dias', '90+' => 'Acima de 90 dias'];

    /** Tabelas do ERP que aparecem em "Atividades recentes" (todas têm condominium_id) e o rótulo de cada uma. */
    public const ACTIVITY_ENTITIES = [
        'erp_entries' => ['Lançamento', 'Lançamentos', 'receipt', '/financeiro/lancamentos'],
        'erp_agreements' => ['Acordo', 'Acordos', 'receipt', '/cobranca/acordos'],
        'erp_invoices' => ['Fatura', 'Faturas', 'file', '/financeiro/faturas'],
        'erp_units' => ['Unidade', 'Unidades', 'building', '/condominios/unidades'],
        'erp_residents' => ['Pessoa da unidade', 'Pessoas', 'users', '/cadastros/proprietarios-e-inquilinos'],
        'erp_occurrences' => ['Ocorrência', 'Ocorrências', 'clipboard', '/operacional/ocorrencias'],
        'erp_maintenances' => ['Manutenção', 'Manutenções', 'calendar', '/operacional/manutencoes'],
        'erp_reservations' => ['Reserva', 'Reservas', 'calendar', '/operacional/reservas-areas-comuns'],
        'erp_announcements' => ['Comunicado', 'Comunicados', 'file', '/comunicacao/comunicados-gerais'],
        'erp_assemblies' => ['Assembleia', 'Assembleias', 'users', '/comunicacao/assembleia-virtual'],
    ];

    public const ACTION_LABELS = ['create' => 'cadastrado', 'update' => 'alterado', 'delete' => 'excluído', 'cancel' => 'cancelado'];

    /** Módulos de nome feminino ("Fatura alterada", "Reserva cancelada"). */
    public const FEMININE = ['erp_invoices', 'erp_units', 'erp_residents', 'erp_occurrences', 'erp_maintenances', 'erp_reservations', 'erp_assemblies'];

    /** "Lançamento cadastrado", "Fatura alterada" (regra pura). */
    public static function activityTitle(string $entity, string $action): string
    {
        $label = self::ACTIVITY_ENTITIES[$entity][0] ?? $entity;
        $verb = self::ACTION_LABELS[$action] ?? $action;
        if (in_array($entity, self::FEMININE, true) && str_ends_with($verb, 'o')) {
            $verb = substr($verb, 0, -1) . 'a';
        }
        return $label . ' ' . $verb;
    }

    private const MONTH_SHORT = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    private const MONTH_LONG = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Competência válida (AAAA-MM) ou a do mês de hoje (regra pura). */
    public static function competenceOrCurrent(mixed $competence, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        return is_string($competence) && RecurringEntries::isCompetence($competence) ? $competence : substr($today, 0, 7);
    }

    /**
     * As $count competências que terminam em $competence, da mais antiga para a mais nova (regra pura).
     *
     * @return list<string>
     */
    public static function window(string $competence, int $count = self::MONTHS): array
    {
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida (AAAA-MM).');
        }
        $end = new \DateTimeImmutable($competence . '-01');
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = $end->modify("-{$i} month")->format('Y-m');
        }
        return $out;
    }

    /** "Nov" ou "novembro de 2026" (regra pura). */
    public static function monthLabel(string $competence, bool $long = false): string
    {
        $month = (int)substr($competence, 5, 2);
        return $long ? self::MONTH_LONG[$month - 1] . ' de ' . substr($competence, 0, 4) : self::MONTH_SHORT[$month - 1];
    }

    /** Percentual com uma casa (regra pura); sem base = null. */
    public static function rate(int $part, int $base): ?float
    {
        return $base > 0 ? round($part / $base * 100, 1) : null;
    }

    /** Situação do condomínio pela inadimplência do mês (regra pura). */
    public static function status(?float $delinquencyRate): string
    {
        return match (true) {
            $delinquencyRate === null => 'Sem lançamentos',
            $delinquencyRate > self::CRITICAL_RATE => 'Crítico',
            $delinquencyRate > self::ATTENTION_RATE => 'Atenção',
            default => 'Em dia',
        };
    }

    /**
     * Financeiro do dashboard no contexto (um condomínio ou todos do escopo).
     *
     * @return array{competence:string, as_of:string, months:list<array>, month:array, delinquency:array, condominiums:list<array>}
     */
    public function finance(User $user, ?int $condominiumId, string $competence, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $months = self::window($competence);
        $ids = $this->ids($user, $condominiumId);
        $series = array_fill_keys($months, ['revenue_cents' => 0, 'revenue_received_cents' => 0, 'expense_cents' => 0, 'expense_paid_cents' => 0]);
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $condos = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            // Por competência, como o balancete (AP-079): lançado e realizado (pago) de cada natureza.
            $stmt = $this->pdo->prepare("SELECT competence,
                    SUM(CASE WHEN nature = 'receita' THEN amount_cents ELSE 0 END) AS revenue,
                    SUM(CASE WHEN nature = 'receita' AND paid_on IS NOT NULL THEN amount_cents ELSE 0 END) AS received,
                    SUM(CASE WHEN nature = 'despesa' THEN amount_cents ELSE 0 END) AS expense,
                    SUM(CASE WHEN nature = 'despesa' AND paid_on IS NOT NULL THEN amount_cents ELSE 0 END) AS paid
                FROM erp_entries
                WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND competence BETWEEN :f AND :t
                GROUP BY competence");
            $stmt->execute(['f' => $months[0], 't' => $competence]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $series[(string)$row['competence']] = ['revenue_cents' => (int)$row['revenue'], 'revenue_received_cents' => (int)$row['received'],
                    'expense_cents' => (int)$row['expense'], 'expense_paid_cents' => (int)$row['paid']];
            }

            // Inadimplência por faixa de atraso: receitas vencidas e não pagas, com os dias contados até hoje.
            $stmt = $this->pdo->prepare("SELECT CASE WHEN DATEDIFF(:t1, due_on) <= 30 THEN '1-30' WHEN DATEDIFF(:t2, due_on) <= 60 THEN '31-60'
                    WHEN DATEDIFF(:t3, due_on) <= 90 THEN '61-90' ELSE '90+' END AS bucket, SUM(amount_cents) AS total
                FROM erp_entries
                WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND nature = 'receita' AND paid_on IS NULL AND due_on < :t4
                GROUP BY bucket");
            $stmt->execute(['t1' => $today, 't2' => $today, 't3' => $today, 't4' => $today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $buckets[(string)$row['bucket']] = (int)$row['total'];
            }

            // Quadro por condomínio: unidades, arrecadação e inadimplência da competência escolhida.
            $stmt = $this->pdo->prepare("SELECT c.id, c.name, c.city, c.state,
                    (SELECT COUNT(*) FROM erp_units u WHERE u.condominium_id = c.id AND u.deleted_at IS NULL AND u.status = 'active') AS units,
                    COALESCE(SUM(CASE WHEN e.nature = 'receita' THEN e.amount_cents END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN e.nature = 'receita' AND e.paid_on IS NOT NULL THEN e.amount_cents END), 0) AS received,
                    COALESCE(SUM(CASE WHEN e.nature = 'receita' AND e.paid_on IS NULL AND e.due_on < :t THEN e.amount_cents END), 0) AS overdue,
                    COALESCE(SUM(CASE WHEN e.nature = 'despesa' THEN e.amount_cents END), 0) AS expense
                FROM condominiums c
                LEFT JOIN erp_entries e ON e.condominium_id = c.id AND e.deleted_at IS NULL AND e.status = 'active' AND e.competence = :m
                WHERE c.id IN ({$in})
                GROUP BY c.id, c.name, c.city, c.state
                ORDER BY c.name");
            $stmt->execute(['t' => $today, 'm' => $competence]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $revenue = (int)$row['revenue'];
                $delinquency = self::rate((int)$row['overdue'], $revenue);
                $city = trim((string)$row['city']);
                $state = trim((string)$row['state']);
                $condos[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'],
                    'city' => $city . ($city !== '' && $state !== '' ? '/' . mb_strtoupper($state) : ''),
                    'units' => (int)$row['units'], 'revenue_cents' => $revenue, 'received_cents' => (int)$row['received'],
                    'overdue_cents' => (int)$row['overdue'], 'expense_cents' => (int)$row['expense'],
                    'received_rate' => self::rate((int)$row['received'], $revenue), 'delinquency_rate' => $delinquency, 'status' => self::status($delinquency)];
            }
        }

        $current = $series[$competence];
        $previous = $series[$months[count($months) - 2]];
        $open = array_sum($buckets);
        return [
            'competence' => $competence,
            'as_of' => $today,
            'months' => array_map(static fn (string $m): array => ['competence' => $m, 'label' => self::monthLabel($m)] + $series[$m], $months),
            'month' => $current + [
                'label' => self::monthLabel($competence, true),
                'result_cents' => $current['revenue_received_cents'] - $current['expense_paid_cents'],
                'balance_cents' => $current['revenue_cents'] - $current['expense_cents'],
                'received_rate' => self::rate($current['revenue_received_cents'], $current['revenue_cents']),
                'previous_revenue_cents' => $previous['revenue_cents'],
                'previous_expense_cents' => $previous['expense_cents'],
            ],
            'delinquency' => ['open_cents' => $open, 'buckets' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::BUCKETS[$key], 'amount_cents' => $buckets[$key]],
                array_keys(self::BUCKETS))],
            'condominiums' => $condos,
        ];
    }

    /**
     * Próximos compromissos (hoje até +30 dias): assembleias convocadas ou abertas, manutenções agendadas
     * e reservas aguardando ou confirmadas. Ordenados por data; no máximo AGENDA_LIMIT.
     *
     * @param array{assemblies?:bool, maintenances?:bool, reservations?:bool} $modules o que o usuário pode ver
     * @return list<array{date:string, time:?string, kind:string, title:string, text:string, href:string}>
     */
    public function agenda(User $user, ?int $condominiumId, array $modules, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $until = (new \DateTimeImmutable($today))->modify('+' . self::AGENDA_DAYS . ' days')->format('Y-m-d');
        $items = [];
        $limit = self::AGENDA_LIMIT;
        if ($modules['assemblies'] ?? false) {
            $stmt = $this->pdo->prepare("SELECT a.id, a.title, a.kind, a.mode, a.first_call_at, c.name AS condominium FROM erp_assemblies a
                JOIN condominiums c ON c.id = a.condominium_id
                WHERE a.condominium_id IN ({$in}) AND a.status IN ('convened','open') AND a.first_call_at BETWEEN :f AND :t
                ORDER BY a.first_call_at LIMIT {$limit}");
            $stmt->execute(['f' => $today . ' 00:00:00', 't' => $until . ' 23:59:59']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = ['date' => substr((string)$r['first_call_at'], 0, 10), 'time' => substr((string)$r['first_call_at'], 11, 5), 'kind' => 'assembly',
                    'title' => (string)$r['title'], 'text' => (string)$r['condominium'] . ' · assembleia ' . ($r['kind'] === 'ordinaria' ? 'ordinária' : 'extraordinária') . ' ' . (string)$r['mode'],
                    'href' => '/comunicacao/assembleia-virtual'];
            }
        }
        if ($modules['maintenances'] ?? false) {
            $stmt = $this->pdo->prepare("SELECT m.id, m.service, m.planned_on, c.name AS condominium FROM erp_maintenances m
                JOIN condominiums c ON c.id = m.condominium_id
                WHERE m.condominium_id IN ({$in}) AND m.deleted_at IS NULL AND m.status = 'active' AND m.planned_on BETWEEN :f AND :t
                ORDER BY m.planned_on LIMIT {$limit}");
            $stmt->execute(['f' => $today, 't' => $until]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = ['date' => (string)$r['planned_on'], 'time' => null, 'kind' => 'maintenance', 'title' => 'Manutenção: ' . (string)$r['service'],
                    'text' => (string)$r['condominium'], 'href' => '/operacional/manutencoes'];
            }
        }
        if ($modules['reservations'] ?? false) {
            $stmt = $this->pdo->prepare("SELECT r.id, r.reserved_on, r.starts_time, r.status, a.name AS area, c.name AS condominium FROM erp_reservations r
                JOIN condominiums c ON c.id = r.condominium_id LEFT JOIN erp_common_areas a ON a.id = r.common_area_id
                WHERE r.condominium_id IN ({$in}) AND r.deleted_at IS NULL AND r.status IN ('active','confirmed') AND r.reserved_on BETWEEN :f AND :t
                ORDER BY r.reserved_on, r.starts_time LIMIT {$limit}");
            $stmt->execute(['f' => $today, 't' => $until]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = ['date' => (string)$r['reserved_on'], 'time' => $r['starts_time'] !== null ? substr((string)$r['starts_time'], 0, 5) : null, 'kind' => 'reservation',
                    'title' => 'Reserva: ' . (string)($r['area'] ?? 'área comum') . ($r['status'] === 'active' ? ' (aguardando aprovação)' : ''),
                    'text' => (string)$r['condominium'], 'href' => $r['status'] === 'active' ? '/operacional/agendamentos-pendentes' : '/operacional/reservas-areas-comuns'];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['date'], $a['time'] ?? '99:99'] <=> [$b['date'], $b['time'] ?? '99:99']);
        return array_slice($items, 0, self::AGENDA_LIMIT);
    }

    /**
     * Últimas ações da equipe nos módulos de ACTIVITY_ENTITIES, só de registros dos condomínios do contexto.
     *
     * @return list<array{at:string, icon:string, title:string, text:string, href:string}>
     */
    public function activities(User $user, ?int $condominiumId): array
    {
        $ids = $this->ids($user, $condominiumId);
        if ($ids === []) {
            return [];
        }
        $allowed = array_flip($ids);
        $entities = array_keys(self::ACTIVITY_ENTITIES);
        $marks = implode(',', array_fill(0, count($entities), '?'));
        $stmt = $this->pdo->prepare("SELECT l.id, l.action, l.entity, l.entity_id, l.created_at, u.first_name, u.last_name
            FROM system_audit_logs l LEFT JOIN users u ON u.id = l.users_id
            WHERE l.entity IN ({$marks}) AND l.entity_id IS NOT NULL ORDER BY l.id DESC LIMIT " . self::ACTIVITY_SCAN);
        $stmt->execute($entities);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Condomínio de cada registro, uma consulta por tabela (nomes vêm da lista fixa acima).
        $byEntity = [];
        foreach ($logs as $log) {
            $byEntity[(string)$log['entity']][] = (int)$log['entity_id'];
        }
        $owner = [];
        foreach ($byEntity as $entity => $entityIds) {
            $list = implode(',', array_unique(array_map('intval', $entityIds)));
            foreach ($this->pdo->query("SELECT id, condominium_id FROM `{$entity}` WHERE id IN ({$list})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $owner[$entity][(int)$row['id']] = (int)$row['condominium_id'];
            }
        }
        $names = [];
        foreach ($this->pdo->query('SELECT id, name FROM condominiums WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int)$row['id']] = (string)$row['name'];
        }

        $out = [];
        foreach ($logs as $log) {
            $entity = (string)$log['entity'];
            $condo = $owner[$entity][(int)$log['entity_id']] ?? null;
            if ($condo === null || !isset($allowed[$condo])) {
                continue;
            }
            [, , $icon, $href] = self::ACTIVITY_ENTITIES[$entity];
            $who = trim((string)$log['first_name'] . ' ' . (string)$log['last_name']);
            $out[] = ['at' => (string)$log['created_at'], 'icon' => $icon,
                'title' => self::activityTitle($entity, (string)$log['action']),
                'text' => '#' . (int)$log['entity_id'] . ' · ' . ($names[$condo] ?? 'Condomínio') . ' · ' . ($who !== '' ? $who : 'Sistema'),
                'href' => $href];
            if (count($out) === self::ACTIVITY_LIMIT) {
                break;
            }
        }
        return $out;
    }

    /** "há 5 min", "há 2 h", "ontem", "08/11" (regra pura). */
    public static function ago(string $at, ?int $now = null): string
    {
        $now ??= time();
        $time = strtotime($at);
        if ($time === false) {
            return '';
        }
        $diff = max(0, $now - $time);
        return match (true) {
            $diff < 60 => 'agora',
            $diff < 3600 => 'há ' . intdiv($diff, 60) . ' min',
            $diff < 86400 && date('Y-m-d', $time) === date('Y-m-d', $now) => 'há ' . intdiv($diff, 3600) . ' h',
            date('Y-m-d', $time) === date('Y-m-d', $now - 86400) => 'ontem',
            default => date('d/m', $time),
        };
    }

    /** Módulos da agenda que o usuário pode ver. @return array{assemblies:bool, maintenances:bool, reservations:bool} */
    public static function agendaModules(User $user): array
    {
        return ['assemblies' => Access::can('erp.assemblies.view', $user), 'maintenances' => Access::can('erp.maintenances.view', $user),
            'reservations' => Access::can('erp.reservations.view', $user)];
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
        if ($condominiumId !== null) {
            if (!in_array($condominiumId, $ids, true)) {
                throw new RegistryNotFound();
            }
            return [$condominiumId];
        }
        return $ids;
    }
}
