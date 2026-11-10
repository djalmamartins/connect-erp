<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Remessas (AP-037, ADR 0156): lotes de boletos enviados ao banco, um por conta bancária e dia.
 * Com o Inter não existe arquivo de remessa (o registro é online, pela API); com CNAB, o mesmo lote
 * vira arquivo quando o adaptador CNAB existir. A tela mostra o que foi enviado e a confirmação do
 * banco: Confirmada (nada aguardando), Enviada (algum aguardando banco), Com recusa (algum recusado).
 * Só leitura; escopo por condomínio (ADR-002).
 */
final class BankRemittances
{
    public const STATUSES = ['confirmed' => 'Confirmada', 'sent' => 'Enviada', 'rejected' => 'Com recusa'];
    public const MODES = ['inter' => 'Registro online (API do Inter), sem arquivo', 'sandbox' => 'Sandbox interno (testes)'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação do lote (regra pura). */
    public static function status(int $pending, int $rejected): string
    {
        return $rejected > 0 ? 'rejected' : ($pending > 0 ? 'sent' : 'confirmed');
    }

    /**
     * @param array{from?:mixed, to?:mixed, status?:mixed, page?:mixed} $filters datas AAAA-MM-DD
     * @return array{data:list<array>, total:int, page:int, per_page:int, pages:int}
     */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        $perPage = 25;
        $page = max(1, (int)(filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        if ($ids === []) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
        $where = 'ch.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $value = is_string($filters[$key] ?? null) ? $filters[$key] : '';
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                $where .= " AND DATE(ch.created_at) {$op} :{$key}";
                $params[$key] = $value;
            }
        }
        $having = '';
        $status = is_string($filters['status'] ?? null) && isset(self::STATUSES[$filters['status']]) ? $filters['status'] : null;
        if ($status === 'rejected') {
            $having = 'HAVING rejected_count > 0';
        } elseif ($status === 'sent') {
            $having = 'HAVING rejected_count = 0 AND pending_count > 0';
        } elseif ($status === 'confirmed') {
            $having = 'HAVING rejected_count = 0 AND pending_count = 0';
        }
        $group = "SELECT ch.bank_account_id, DATE(ch.created_at) AS sent_on, MIN(ch.condominium_id) AS condominium_id, MIN(ch.adapter) AS adapter,
                COUNT(*) AS charges_count, SUM(ch.amount_cents) AS total_cents,
                SUM(ch.status = 'pending') AS pending_count, SUM(ch.status = 'rejected') AS rejected_count,
                SUM(ch.status IN ('registered','paid')) AS confirmed_count, SUM(ch.status = 'cancelled') AS cancelled_count
            FROM erp_bank_charges ch WHERE {$where} GROUP BY ch.bank_account_id, DATE(ch.created_at) {$having}";
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ({$group}) g");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare("SELECT g.*, b.name AS bank_account_name, b.bank_code, c.name AS condominium_name FROM ({$group}) g
            JOIN erp_bank_accounts b ON b.id = g.bank_account_id JOIN condominiums c ON c.id = g.condominium_id
            ORDER BY g.sent_on DESC, g.bank_account_id LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = array_map(static function (array $r): array {
            $status = self::status((int)$r['pending_count'], (int)$r['rejected_count']);
            return [
                'bank_account_id' => (int)$r['bank_account_id'], 'bank_account_name' => (string)$r['bank_account_name'], 'bank_code' => (string)$r['bank_code'],
                'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
                'sent_on' => (string)$r['sent_on'], 'adapter' => (string)$r['adapter'], 'mode' => self::MODES[$r['adapter']] ?? (string)$r['adapter'],
                'charges_count' => (int)$r['charges_count'], 'total_cents' => (int)$r['total_cents'],
                'confirmed_count' => (int)$r['confirmed_count'], 'pending_count' => (int)$r['pending_count'],
                'rejected_count' => (int)$r['rejected_count'], 'cancelled_count' => (int)$r['cancelled_count'],
                'status' => $status, 'status_label' => self::STATUSES[$status],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int)ceil($total / $perPage)];
    }
}
