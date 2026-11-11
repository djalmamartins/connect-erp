<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Previsão de caixa do condomínio (ADR 0186), somente leitura: o saldo das contas bancárias hoje e, mês a mês, o que
 * deve entrar e sair, com o saldo previsto no fim de cada mês. Serve para o síndico ver antes se vai faltar dinheiro.
 *
 * - Saldo de hoje: as contas bancárias do condomínio (AccountingReports::accountBalances), aplicação incluída.
 * - Entradas do mês: contas a receber em aberto com vencimento no mês (a partir de hoje no mês corrente), pelo valor
 *   cheio. A receber vencido não entra na previsão (pode não ser pago): aparece à parte, em overdue_receivables_cents.
 * - Saídas do mês: contas a pagar em aberto com vencimento no mês. A pagar vencido entra no primeiro mês (precisa ser
 *   pago) e aparece em overdue_payables_cents.
 * - Lançamentos fixos (AP-021): os modelos ativos vigentes no mês que ainda não geraram o lançamento da competência
 *   entram pelo valor e dia do modelo. O que já foi gerado entra como conta em aberto, sem contar duas vezes.
 *
 * De 1 a 12 meses (padrão 6). Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404).
 */
final class CashForecast
{
    public const DEFAULT_MONTHS = 6;
    public const MAX_MONTHS = 12;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<array{competence:string, from:string, to:string}> as janelas da previsão (regra pura) */
    public static function windows(string $today, int $months): array
    {
        $first = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $first->modify("+{$i} month");
            $out[] = ['competence' => $month->format('Y-m'), 'from' => $i === 0 ? $today : $month->format('Y-m-d'), 'to' => $month->format('Y-m-t')];
        }
        return $out;
    }

    /**
     * Saldo mês a mês (regra pura).
     *
     * @param list<array{competence:string, from:string, to:string, receivables_cents:int, payables_cents:int, recurring_in_cents:int, recurring_out_cents:int}> $months
     * @return array{months:list<array>, lowest:?array{competence:string, closing_cents:int}, warnings:list<array>}
     */
    public static function project(int $openingCents, int $overduePayablesCents, array $months): array
    {
        $balance = $openingCents;
        $out = [];
        $warnings = [];
        $lowest = null;
        foreach ($months as $i => $m) {
            $in = $m['receivables_cents'] + $m['recurring_in_cents'];
            $outflow = $m['payables_cents'] + $m['recurring_out_cents'] + ($i === 0 ? $overduePayablesCents : 0);
            $opening = $balance;
            $balance += $in - $outflow;
            $row = $m + ['opening_cents' => $opening, 'in_cents' => $in, 'out_cents' => $outflow, 'net_cents' => $in - $outflow, 'closing_cents' => $balance];
            $out[] = $row;
            if ($lowest === null || $balance < $lowest['closing_cents']) {
                $lowest = ['competence' => $m['competence'], 'closing_cents' => $balance];
            }
        }
        if ($lowest !== null && $lowest['closing_cents'] < 0) {
            $first = array_values(array_filter($out, static fn (array $r): bool => $r['closing_cents'] < 0))[0];
            $warnings[] = ['code' => 'negative_balance', 'message' => 'O saldo previsto fica negativo em ' . $first['competence'] . '.', 'competence' => $first['competence']];
        }
        if ($overduePayablesCents > 0) {
            $warnings[] = ['code' => 'overdue_payables', 'message' => 'Há contas a pagar vencidas: entram no primeiro mês da previsão.', 'competence' => $months[0]['competence'] ?? null];
        }
        return ['months' => $out, 'lowest' => $lowest, 'warnings' => $warnings];
    }

    public function build(User $user, ?int $condominiumId, mixed $months = null, ?string $today = null): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $count = $months === null || $months === '' ? self::DEFAULT_MONTHS
            : filter_var($months, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_MONTHS]]);
        if ($count === false) {
            throw new InvalidArgumentException('Informe de 1 a ' . self::MAX_MONTHS . ' meses.');
        }
        $today ??= date('Y-m-d');
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $opening = (int)((new AccountingReports($this->pdo, $this->scope))->accountBalances($user, $condominiumId, $today)['totals']['closing_cents'] ?? 0);
        $open = "condominium_id = :c AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL";
        $overdueIn = $this->sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE {$open} AND nature = 'receita' AND due_on < :t", ['c' => $condominiumId, 't' => $today]);
        $overdueOut = $this->sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE {$open} AND nature = 'despesa' AND due_on < :t", ['c' => $condominiumId, 't' => $today]);
        $templates = $this->rows("SELECT id, nature, amount_cents, day_of_month, starts_competence, ends_competence FROM erp_recurring_entries
            WHERE condominium_id = ? AND status = 'active' AND deleted_at IS NULL", [$condominiumId]);
        $generated = [];
        foreach ($this->rows('SELECT recurring_entry_id, competence FROM erp_entries WHERE condominium_id = ? AND recurring_entry_id IS NOT NULL AND deleted_at IS NULL',
            [$condominiumId]) as $g) {
            $generated[(int)$g['recurring_entry_id'] . '|' . (string)$g['competence']] = true;
        }
        $rows = [];
        foreach (self::windows($today, (int)$count) as $w) {
            $params = ['c' => $condominiumId, 'f' => $w['from'], 't' => $w['to']];
            $recurring = ['receita' => 0, 'despesa' => 0];
            foreach ($templates as $tpl) {
                $due = $w['competence'] . '-' . str_pad((string)$tpl['day_of_month'], 2, '0', STR_PAD_LEFT);
                $active = $tpl['starts_competence'] <= $w['competence'] && ($tpl['ends_competence'] === null || $tpl['ends_competence'] >= $w['competence']);
                if ($active && $due >= $w['from'] && !isset($generated[(int)$tpl['id'] . '|' . $w['competence']])) {
                    $recurring[(string)$tpl['nature']] += (int)$tpl['amount_cents'];
                }
            }
            $rows[] = $w + [
                'receivables_cents' => $this->sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE {$open} AND nature = 'receita' AND due_on BETWEEN :f AND :t", $params),
                'payables_cents' => $this->sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE {$open} AND nature = 'despesa' AND due_on BETWEEN :f AND :t", $params),
                'recurring_in_cents' => $recurring['receita'], 'recurring_out_cents' => $recurring['despesa'],
            ];
        }
        return ['condominium_id' => $condominiumId, 'as_of' => $today, 'opening_cash_cents' => $opening,
            'overdue_receivables_cents' => $overdueIn, 'overdue_payables_cents' => $overdueOut] + self::project($opening, $overdueOut, $rows);
    }

    private function sum(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
