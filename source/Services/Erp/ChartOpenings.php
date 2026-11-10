<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Saldo inicial do plano de contas (AP-161, ADR 0166), no mesmo padrão do saldo inicial por centro de
 * custo (AP-162, ErpSettings::setOpening): um valor com sinal por condomínio e conta, numa data até hoje,
 * auditado. Só a conta analítica (do padrão ou do condomínio, ativa) recebe saldo; a sintética é a soma
 * das analíticas abaixo dela, calculada na leitura (tree()).
 *
 * A gravação não usa ON DUPLICATE KEY: lê o valor anterior e faz UPDATE ou INSERT (mesmo efeito, e o
 * valor anterior vai para a auditoria). Escopo (ADR-002): fora do escopo = CondominiumAccessDenied (404).
 */
final class ChartOpenings
{
    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Árvore do saldo inicial (regra pura): cada analítica com saldo e todas as sintéticas acima dela com a
     * soma. Ordem: receitas e depois despesas, por código.
     *
     * @param list<array{chart_account_id:int, code:string, name:string, nature:string, opened_on:string, amount_cents:int, notes:?string}> $rows
     * @param array<string, string> $names nome das sintéticas por código
     * @return array{rows:list<array>, totals:array{revenue_cents:int, expense_cents:int, total_cents:int}}
     */
    public static function tree(array $rows, array $names = []): array
    {
        $tree = [];
        $keys = ['receita' => 'revenue_cents', 'despesa' => 'expense_cents', 'asset' => 'asset_cents', 'liability' => 'liability_cents', 'equity' => 'equity_cents'];
        $totals = array_fill_keys(array_values($keys), 0);
        foreach ($rows as $row) {
            $parts = explode('.', (string)$row['code']);
            for ($depth = 1; $depth <= count($parts); $depth++) {
                $code = implode('.', array_slice($parts, 0, $depth));
                $analytic = $depth === count($parts);
                $key = $row['nature'] . '|' . $code;
                $tree[$key] ??= ['code' => $code, 'name' => $analytic ? (string)$row['name'] : ($names[$code] ?? $code), 'nature' => (string)$row['nature'],
                    'level' => $depth, 'analytic' => $analytic, 'chart_account_id' => null, 'opened_on' => null, 'notes' => null, 'amount_cents' => 0];
                $tree[$key]['amount_cents'] += (int)$row['amount_cents'];
                if ($analytic) {
                    $tree[$key] = ['analytic' => true, 'chart_account_id' => (int)$row['chart_account_id'], 'opened_on' => (string)$row['opened_on'], 'notes' => $row['notes'] ?? null] + $tree[$key];
                }
            }
            $totals[$keys[$row['nature']] ?? 'expense_cents'] += (int)$row['amount_cents'];
        }
        $sortable = static fn (string $code): string => implode('.', array_map(static fn (string $p): string => str_pad($p, 6, '0', STR_PAD_LEFT), explode('.', $code)));
        $order = array_flip(array_keys($keys));
        uasort($tree, static fn (array $a, array $b): int => [$order[$a['nature']] ?? 5, $sortable($a['code'])] <=> [$order[$b['nature']] ?? 5, $sortable($b['code'])]);
        // total_cents continua sendo receita + despesa (contrato do ADR 0166); as patrimoniais têm o total por natureza.
        return ['rows' => array_values($tree), 'totals' => $totals + ['total_cents' => $totals['revenue_cents'] + $totals['expense_cents']]];
    }

    public function set(User $user, int $condominiumId, int $accountId, mixed $openedOn, mixed $amount, mixed $notes = null): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $account = $this->pdo->prepare("SELECT id, kind FROM erp_chart_accounts WHERE id = :id AND deleted_at IS NULL AND status = 'active'
            AND (condominium_id IS NULL OR condominium_id = :c)");
        $account->execute(['id' => $accountId, 'c' => $condominiumId]);
        $found = $account->fetch(PDO::FETCH_ASSOC);
        if ($found === false) {
            throw new InvalidArgumentException('Escolha uma conta ativa do plano padrão ou deste condomínio.');
        }
        if ($found['kind'] !== 'analitica') {
            throw new InvalidArgumentException('Só a conta analítica recebe saldo inicial; a sintética é a soma das analíticas.');
        }
        $date = is_string($openedOn) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $openedOn) : false;
        if ($date === false || $date->format('Y-m-d') !== $openedOn || $openedOn > date('Y-m-d')) {
            throw new InvalidArgumentException('Data do saldo inicial inválida (AAAA-MM-DD, até hoje).');
        }
        $cents = ErpSettings::signedCents($amount);
        $note = is_string($notes) && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null;
        $before = $this->pdo->prepare('SELECT id, amount_cents, opened_on FROM erp_chart_account_openings WHERE condominium_id = :c AND chart_account_id = :a');
        $before->execute(['c' => $condominiumId, 'a' => $accountId]);
        $old = $before->fetch(PDO::FETCH_ASSOC) ?: null;
        $values = ['c' => $condominiumId, 'a' => $accountId, 'd' => $openedOn, 'v' => $cents, 'n' => $note, 'u' => (int)$user->id];
        $update = 'UPDATE erp_chart_account_openings SET opened_on = :d, amount_cents = :v, notes = :n, updated_by = :u WHERE condominium_id = :c AND chart_account_id = :a';
        if ($old === null) {
            try {
                $this->pdo->prepare('INSERT INTO erp_chart_account_openings (condominium_id, chart_account_id, opened_on, amount_cents, notes, updated_by)
                    VALUES (:c, :a, :d, :v, :n, :u)')->execute($values);
            } catch (\PDOException $error) {
                // Gravação simultânea da mesma conta (chave única): a segunda vira atualização, sem erro 500 (ADR 0172).
                if (!in_array($error->errorInfo[1] ?? null, [1062, 19], true)) {
                    throw $error;
                }
                $this->pdo->prepare($update)->execute($values);
            }
        } else {
            $this->pdo->prepare($update)->execute($values);
        }
        Audit::record($old === null ? 'create' : 'update', 'erp_chart_account_openings', $accountId,
            $old === null ? null : ['amount_cents' => (int)$old['amount_cents'], 'opened_on' => (string)$old['opened_on']],
            ['condominium_id' => $condominiumId, 'opened_on' => $openedOn, 'amount_cents' => $cents]);
        return $this->list($user, $condominiumId);
    }

    public function list(User $user, int $condominiumId): array
    {
        $this->scope->assertAccess($user, $condominiumId);
        $stmt = $this->pdo->prepare('SELECT o.chart_account_id, a.code, a.name, a.nature, o.opened_on, o.amount_cents, o.notes, o.updated_at
            FROM erp_chart_account_openings o JOIN erp_chart_accounts a ON a.id = o.chart_account_id
            WHERE o.condominium_id = :c AND a.deleted_at IS NULL ORDER BY a.code');
        $stmt->execute(['c' => $condominiumId]);
        $rows = array_map(static fn (array $r): array => ['chart_account_id' => (int)$r['chart_account_id'], 'code' => (string)$r['code'], 'name' => (string)$r['name'],
            'nature' => (string)$r['nature'], 'opened_on' => (string)$r['opened_on'], 'amount_cents' => (int)$r['amount_cents'], 'notes' => $r['notes']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $names = [];
        $synthetic = $this->pdo->prepare("SELECT code, name FROM erp_chart_accounts WHERE deleted_at IS NULL AND kind = 'sintetica'
            AND (condominium_id IS NULL OR condominium_id = :c) ORDER BY condominium_id IS NULL DESC");
        $synthetic->execute(['c' => $condominiumId]);
        foreach ($synthetic->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $names[(string)$s['code']] = (string)$s['name'];   // a do condomínio sobrescreve a do padrão
        }
        return ['condominium_id' => $condominiumId] + self::tree($rows, $names);
    }
}
