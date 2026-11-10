<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\AuditMask;

/**
 * Administração (AP-091) e Relatórios Enviados (AP-100), ADR 0168. Somente leitura.
 *
 * - overview(): dados de atendimento da administradora (parâmetros AP-147 do ErpSettings: e-mail, telefone e o
 *   nome exibido; padrão do código quando não há valor) e o resumo do escopo do usuário (condomínios,
 *   unidades, moradores, usuários com acesso, contas bancárias e fechamentos do mês anterior).
 * - sent(): histórico dos relatórios exportados, lido da auditoria (action 'export' em entidades erp_*, a mesma
 *   que conta o limite de 30 CSV por hora). Quem acessa todos os condomínios vê tudo; os demais veem as
 *   exportações de condomínios do seu escopo e as próprias exportações sem condomínio. IP mascarado.
 */
final class AdminReports
{
    public const ADMIN_KEYS = ['app.display_name', 'admin.support_email', 'admin.support_phone'];
    public const MAX_ROWS = 5000;
    /** Linhas lidas da auditoria antes do filtro de escopo (o filtro é feito aqui, no PHP). */
    public const MAX_SCAN = 20000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    public function overview(User $user, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $values = [];
        $stmt = $this->pdo->query("SELECT setting_key, value_json FROM erp_settings WHERE condominium_id IS NULL AND setting_key IN ('"
            . implode("','", self::ADMIN_KEYS) . "')");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string)$row['setting_key']] = json_decode((string)$row['value_json'], true);
        }
        $admin = [];
        foreach (self::ADMIN_KEYS as $key) {
            $value = $values[$key] ?? ErpSettings::CATALOG[$key]['default'];
            $admin[str_replace(['app.', 'admin.'], '', $key)] = is_scalar($value) ? (string)$value : '';
        }
        $ids = array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
        $previous = (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
        $counts = ['condominiums' => count($ids), 'units' => 0, 'residents' => 0, 'users' => 0, 'bank_accounts' => 0, 'closed_previous_month' => 0];
        if ($ids !== []) {
            $in = implode(',', $ids);
            $counts['units'] = $this->count("SELECT COUNT(*) FROM erp_units WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'");
            $counts['residents'] = $this->count("SELECT COUNT(*) FROM erp_residents WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'");
            $counts['users'] = $this->count("SELECT COUNT(DISTINCT user_id) FROM user_condominium_access WHERE condominium_id IN ({$in})");
            $counts['bank_accounts'] = $this->count("SELECT COUNT(*) FROM erp_bank_accounts WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'");
            $closed = $this->pdo->prepare("SELECT COUNT(*) FROM erp_month_closings WHERE condominium_id IN ({$in}) AND competence = :m AND status = 'closed'");
            $closed->execute(['m' => $previous]);
            $counts['closed_previous_month'] = (int)$closed->fetchColumn();
        }
        return ['administrator' => $admin, 'global_access' => $this->scope->hasGlobalAccess($user), 'previous_month' => $previous,
            'summary' => $counts + ['open_previous_month' => max(0, $counts['condominiums'] - $counts['closed_previous_month'])]];
    }

    /**
     * Exportação lida do contexto da auditoria (regra pura). O contexto guarda {changes: {campo: {after}}}; sem
     * condomínio, o valor vem vazio.
     *
     * @return array{report:string, condominium_id:?int, bytes:?int}
     */
    public static function exportContext(?string $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        $after = static fn (string $key): mixed => is_array($data) ? ($data['changes'][$key]['after'] ?? null) : null;
        $condominium = filter_var($after('condominium_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $bytes = filter_var($after('bytes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $report = $after('report') ?? $after('kind');
        return ['report' => is_scalar($report) && preg_match('/^[a-z0-9_-]{1,60}$/', (string)$report) ? (string)$report : '',
            'condominium_id' => $condominium === false ? null : (int)$condominium, 'bytes' => $bytes === false ? null : (int)$bytes];
    }

    public function sent(User $user, mixed $from, mixed $to, mixed $report = null): array
    {
        [$from, $to] = CollectionReports::period(is_string($from) ? $from : '', is_string($to) ? $to : '');
        $report = is_string($report) && preg_match('/^[a-z0-9_-]{1,60}$/', $report) ? $report : null;
        $global = $this->scope->hasGlobalAccess($user);
        $allowed = array_flip(array_map('intval', $this->scope->allowedIds($user)));
        $stmt = $this->pdo->prepare("SELECT l.id, l.users_id, l.created_at, l.entity, l.ip, l.context_json, u.first_name, u.last_name
            FROM system_audit_logs l LEFT JOIN users u ON u.id = l.users_id
            WHERE l.action = 'export' AND l.entity LIKE 'erp\\_%' ESCAPE '\\' AND l.created_at BETWEEN :f AND :t ORDER BY l.id DESC LIMIT " . (self::MAX_SCAN + 1));
        $stmt->execute(['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59']);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($raw) > self::MAX_SCAN;
        $rows = [];
        foreach (array_slice($raw, 0, self::MAX_SCAN) as $r) {
            $context = self::exportContext($r['context_json']);
            $mine = $r['users_id'] !== null && (int)$r['users_id'] === (int)$user->id;
            $visible = $global || ($context['condominium_id'] !== null ? isset($allowed[$context['condominium_id']]) : $mine);
            if (!$visible || ($report !== null && $context['report'] !== $report)) {
                continue;
            }
            if (count($rows) === self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $name = trim((string)$r['first_name'] . ' ' . (string)$r['last_name']);
            $rows[] = ['id' => (int)$r['id'], 'created_at' => (string)$r['created_at'], 'user_name' => $name !== '' ? AuditMask::text($name) : 'Sistema',
                'entity' => (string)$r['entity'], 'ip' => RegistryReports::maskIp($r['ip'])] + $context;
        }
        $byReport = [];
        foreach ($rows as $row) {
            $byReport[$row['report']] = ($byReport[$row['report']] ?? 0) + 1;
        }
        ksort($byReport);
        return ['from' => $from, 'to' => $to, 'report' => $report, 'truncated' => $truncated, 'rows' => $rows,
            'totals' => ['exports' => count($rows), 'bytes' => array_sum(array_map(static fn (array $r): int => (int)$r['bytes'], $rows)), 'by_report' => $byReport]];
    }

    private function count(string $sql): int
    {
        return (int)$this->pdo->query($sql)->fetchColumn();
    }
}
