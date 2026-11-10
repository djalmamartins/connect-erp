<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Prestação de contas mensal (AP-101, ADR 0166): o pacote de um condomínio num mês, montado só com os
 * services que já existem, sem SQL próprio:
 *
 * - balancete da competência (AP-079, FinancialReports::trialBalance);
 * - DRE do mês por competência (AP-082, AccountingReports::incomeStatement);
 * - saldos das contas bancárias no mês (AP-073, AccountingReports::accountBalances);
 * - inadimplência no último dia do mês (Agreements::delinquency, a mesma do AP-057);
 * - situação do fechamento mensal (AP-030, MonthClosing::status).
 *
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404). Somente leitura.
 */
final class Accountability
{
    /** Unidades inadimplentes listadas no pacote; o total sempre considera todas. */
    public const MAX_UNITS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;
    /** @var \Closure(User, int, string): array */
    private \Closure $delinquency;

    /** @param (callable(User, int, string): array)|null $delinquency substitui Agreements::delinquency (testes) */
    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?callable $delinquency = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->delinquency = $delinquency !== null ? \Closure::fromCallable($delinquency)
            : fn (User $user, int $condominiumId, string $asOf): array => (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominiumId, $asOf);
    }

    /** @return array{0:string, 1:string, 2:string} competência, primeiro e último dia */
    public static function month(mixed $month): array
    {
        if (!is_string($month) || !RecurringEntries::isCompetence($month)) {
            throw new InvalidArgumentException('Informe o mês (AAAA-MM).');
        }
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        return [$month, $first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }

    public function build(User $user, ?int $condominiumId, mixed $month): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$competence, $from, $to] = self::month($month);
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $accounting = new AccountingReports($this->pdo, $this->scope);
        return self::assemble($condominiumId, $competence, $from, $to,
            (new MonthClosing($this->pdo, $this->scope))->status($user, $condominiumId, $competence),
            (new FinancialReports($this->pdo, $this->scope))->trialBalance($user, $condominiumId, $competence, $competence),
            $accounting->incomeStatement($user, $condominiumId, $from, $to, 'competence'),
            $accounting->accountBalances($user, $condominiumId, null, $from, $to),
            ($this->delinquency)($user, $condominiumId, $to));
    }

    /**
     * Junta as partes (regra pura): resume o fechamento e a inadimplência e confere o pacote.
     * Avisos: mês não fechado, receita/despesa da competência ainda em aberto e contas vencidas no fim do mês.
     */
    public static function assemble(int $condominiumId, string $competence, string $from, string $to, array $closing, array $trial,
        array $income, array $balances, array $delinquency): array
    {
        $units = array_map(static fn (array $u): array => ['unit_id' => $u['unit_id'], 'unit_label' => (string)$u['unit_label'], 'entries' => count($u['entries'] ?? []),
            'amount_cents' => (int)$u['amount_cents'], 'charges_cents' => (int)($u['charges_cents'] ?? 0), 'total_cents' => (int)($u['total_cents'] ?? $u['amount_cents']),
            'oldest_due_on' => (string)$u['oldest_due_on'], 'max_days_late' => (int)$u['max_days_late']], $delinquency['units'] ?? []);
        usort($units, static fn (array $a, array $b): int => [$b['amount_cents'], $a['unit_label']] <=> [$a['amount_cents'], $b['unit_label']]);
        $delinquentTruncated = count($units) > self::MAX_UNITS;
        $t = $delinquency['totals'] ?? [];
        $delinquent = ['as_of' => (string)($delinquency['as_of'] ?? $to), 'truncated' => $delinquentTruncated, 'units' => array_slice($units, 0, self::MAX_UNITS),
            'totals' => ['units' => (int)($t['units'] ?? count($units)), 'entries' => (int)($t['entries'] ?? 0), 'amount_cents' => (int)($t['amount_cents'] ?? 0),
                'charges_cents' => (int)($t['charges_cents'] ?? 0), 'total_cents' => (int)($t['total_cents'] ?? 0), 'buckets' => $t['buckets'] ?? []]];

        $status = (string)($closing['status'] ?? 'open');
        $close = $closing['closing'] ?? null;
        $closingPart = ['status' => $status, 'closed_at' => $close['closed_at'] ?? null, 'closed_by' => $close['closed_by'] ?? null,
            'reopened_at' => $close['reopened_at'] ?? null, 'reopen_reason' => $close['reopen_reason'] ?? null];

        $tt = $trial['totals'] ?? [];
        $openRevenue = (int)($tt['open_revenue_cents'] ?? 0);
        $openExpense = (int)($tt['open_expense_cents'] ?? 0);
        $bank = $balances['totals'] ?? ['opening_cents' => 0, 'received_cents' => 0, 'paid_cents' => 0, 'closing_cents' => 0];
        $warnings = [];
        if ($status !== 'closed') {
            $warnings[] = ['code' => 'month_open', 'message' => 'O mês ainda não foi fechado: os números podem mudar.'];
        }
        if ($openRevenue > 0 || $openExpense > 0) {
            $warnings[] = ['code' => 'open_entries', 'message' => 'Há receitas ou despesas da competência ainda em aberto.'];
        }
        if ($delinquent['totals']['amount_cents'] > 0) {
            $warnings[] = ['code' => 'delinquency', 'message' => 'Há contas vencidas em aberto no fim do mês.'];
        }
        return [
            'condominium_id' => $condominiumId, 'competence' => $competence, 'from' => $from, 'to' => $to,
            'truncated' => $delinquentTruncated || !empty($income['truncated']) || !empty($balances['truncated']),
            'closing' => $closingPart,
            'trial_balance' => ['rows' => $trial['rows'] ?? [], 'totals' => $tt],
            'income_statement' => ['rows' => $income['rows'] ?? [], 'totals' => $income['totals'] ?? []],
            'bank_accounts' => ['accounts' => $balances['accounts'] ?? [], 'totals' => $bank],
            'delinquency' => $delinquent,
            'summary' => [
                'revenue_cents' => (int)($income['totals']['revenue_cents'] ?? 0), 'expense_cents' => (int)($income['totals']['expense_cents'] ?? 0),
                'result_cents' => (int)($income['totals']['result_cents'] ?? 0),
                'cash_in_cents' => (int)$bank['received_cents'], 'cash_out_cents' => (int)$bank['paid_cents'],
                'bank_opening_cents' => (int)$bank['opening_cents'], 'bank_closing_cents' => (int)$bank['closing_cents'],
                'open_revenue_cents' => $openRevenue, 'open_expense_cents' => $openExpense,
                'delinquent_cents' => $delinquent['totals']['amount_cents'],
            ],
            'warnings' => $warnings,
        ];
    }
}
