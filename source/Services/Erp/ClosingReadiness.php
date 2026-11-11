<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência antes de fechar o mês (AP-030, ADR 0181), somente leitura. Diz o que precisa de atenção antes de fechar
 * a competência, para a prestação de contas sair certa. O fechamento não é bloqueado: a tela mostra a lista e quem
 * fecha decide.
 *
 * Erro (o pacote do mês sai errado se fechar assim):
 * - balance_sheet: o Balanço no último dia do mês não fecha (diferença diferente de zero);
 * - paid_without_bank: lançamento pago sem conta bancária (o caixa e o Razão bancário não batem).
 * Aviso (vale conferir):
 * - previous_open: o mês anterior tem lançamentos e não está fechado;
 * - open_entries: receitas ou despesas da competência ainda em aberto;
 * - unmatched_statement: linhas do extrato importado (AP-140) do mês sem conciliação;
 * - negative_bank: conta bancária com saldo negativo no último dia do mês.
 * Informação: already_closed, quando a competência já está fechada.
 *
 * Escopo (ADR-002): um condomínio, obrigatório; fora do escopo = CondominiumAccessDenied (404).
 */
final class ClosingReadiness
{
    public const ERRORS = ['balance_sheet', 'paid_without_bank'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Regra pura: dos fatos levantados, a lista de conferências e se o mês está pronto (sem erro).
     *
     * @param array{status:string, previous_competence:string, previous_status:?string, previous_entries:int, open_revenue_cents:int,
     *   open_expense_cents:int, unmatched_lines:int, unmatched_cents:int, negative_banks:list<array{name:string, closing_cents:int}>,
     *   balance_difference_cents:int, paid_without_bank:int} $facts
     * @return array{ready:bool, errors:int, warnings:int, checks:list<array{code:string, level:string, message:string, count:int, amount_cents:int}>}
     */
    public static function evaluate(array $facts): array
    {
        $money = static fn (int $c): string => 'R$ ' . number_format($c / 100, 2, ',', '.');
        $checks = [];
        $add = static function (string $code, string $level, string $message, int $count = 0, int $cents = 0) use (&$checks): void {
            $checks[] = ['code' => $code, 'level' => $level, 'message' => $message, 'count' => $count, 'amount_cents' => $cents];
        };
        if ($facts['status'] === 'closed') {
            $add('already_closed', 'info', 'A competência já está fechada. Para mudar algo, reabra com motivo.');
        }
        if ($facts['balance_difference_cents'] !== 0) {
            $add('balance_sheet', 'error', 'O Balanço do último dia do mês não fecha: diferença de ' . $money($facts['balance_difference_cents'])
                . '. Confira os saldos iniciais (AP-161) e os lançamentos patrimoniais.', 1, $facts['balance_difference_cents']);
        }
        if ($facts['paid_without_bank'] > 0) {
            $add('paid_without_bank', 'error', $facts['paid_without_bank'] . ' lançamento(s) pago(s) sem conta bancária: informe a conta para o caixa bater.',
                $facts['paid_without_bank']);
        }
        if ($facts['previous_entries'] > 0 && $facts['previous_status'] !== 'closed') {
            $add('previous_open', 'warning', 'O mês anterior (' . $facts['previous_competence'] . ') tem lançamentos e não está fechado.', $facts['previous_entries']);
        }
        $open = $facts['open_revenue_cents'] + $facts['open_expense_cents'];
        if ($open > 0) {
            $add('open_entries', 'warning', 'Há ' . $money($facts['open_revenue_cents']) . ' de receitas e ' . $money($facts['open_expense_cents'])
                . ' de despesas da competência em aberto.', 0, $open);
        }
        if ($facts['unmatched_lines'] > 0) {
            $add('unmatched_statement', 'warning', $facts['unmatched_lines'] . ' linha(s) do extrato do mês sem conciliação (AP-140), somando '
                . $money(abs($facts['unmatched_cents'])) . '.', $facts['unmatched_lines'], $facts['unmatched_cents']);
        }
        foreach ($facts['negative_banks'] as $bank) {
            $add('negative_bank', 'warning', 'A conta ' . $bank['name'] . ' fecha o mês com saldo negativo (' . $money($bank['closing_cents']) . ').', 1, $bank['closing_cents']);
        }
        $count = static fn (string $level): int => count(array_filter($checks, static fn (array $c): bool => $c['level'] === $level));
        return ['ready' => $count('error') === 0, 'errors' => $count('error'), 'warnings' => $count('warning'), 'checks' => $checks];
    }

    public function check(User $user, ?int $condominiumId, mixed $competence): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$month, $from, $to] = Accountability::month($competence);   // 422 com competência inválida
        $this->scope->assertAccess($user, $condominiumId);         // 404 antes de consultar
        $previous = (new \DateTimeImmutable($from))->modify('-1 month')->format('Y-m');
        $status = (new MonthClosing($this->pdo, $this->scope))->status($user, $condominiumId, $month);
        $balances = (new AccountingReports($this->pdo, $this->scope))->accountBalances($user, $condominiumId, $to);
        $sheet = (new BalanceSheet($this->pdo, $this->scope))->build($user, $condominiumId, $to);
        $unmatched = $this->row("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents), 0) AS cents FROM erp_bank_statement_lines
            WHERE condominium_id = ? AND status = 'unmatched' AND posted_on BETWEEN ? AND ?", [$condominiumId, $from, $to]);
        $facts = [
            'status' => (string)$status['status'],
            'previous_competence' => $previous,
            'previous_status' => $this->value('SELECT status FROM erp_month_closings WHERE condominium_id = ? AND competence = ?', [$condominiumId, $previous]),
            'previous_entries' => (int)$this->value("SELECT COUNT(*) FROM erp_entries WHERE condominium_id = ? AND competence = ? AND status = 'active' AND deleted_at IS NULL",
                [$condominiumId, $previous]),
            'open_revenue_cents' => (int)($status['summary']['open_revenue_cents'] ?? 0),
            'open_expense_cents' => (int)($status['summary']['open_expense_cents'] ?? 0),
            'unmatched_lines' => (int)$unmatched['n'], 'unmatched_cents' => (int)$unmatched['cents'],
            'negative_banks' => array_values(array_map(static fn (array $b): array => ['name' => (string)$b['name'], 'closing_cents' => (int)$b['closing_cents']],
                array_filter($balances['accounts'] ?? [], static fn (array $b): bool => (int)($b['closing_cents'] ?? 0) < 0))),
            'balance_difference_cents' => (int)$sheet['diferenca'],
            'paid_without_bank' => (int)$this->value("SELECT COUNT(*) FROM erp_entries WHERE condominium_id = ? AND competence = ? AND status = 'active' AND deleted_at IS NULL
                AND paid_on IS NOT NULL AND bank_account_id IS NULL", [$condominiumId, $month]),
        ];
        return ['condominium_id' => $condominiumId, 'competence' => $month, 'as_of' => $to] + self::evaluate($facts);
    }

    private function value(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @return array{n:int|string, cents:int|string} */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'cents' => 0];
    }
}
