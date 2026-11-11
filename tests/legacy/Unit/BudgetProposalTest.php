<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BudgetProposal;

/**
 * ADR 0187: proposta de previsão orçamentária a partir do realizado. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2). O PDO de teste só traduz o upsert do MySQL do ErpSettings::setBudget.
 */
final class BudgetProposalTest extends TestCase
{
    public const MYSQL = 'ON DUPLICATE KEY UPDATE amount_cents = VALUES(amount_cents), updated_by = VALUES(updated_by)';
    public const SQLITE = 'ON CONFLICT(condominium_id, year, chart_account_id, month) DO UPDATE SET amount_cents = excluded.amount_cents, updated_by = excluded.updated_by';
    private const TODAY = '2026-11-11';

    private PDO $pdo;
    private BudgetProposal $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(BudgetProposalTest::MYSQL, BudgetProposalTest::SQLITE, $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(BudgetProposalTest::MYSQL, BudgetProposalTest::SQLITE, $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, description TEXT, amount_cents INTEGER, competence TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)",
            'CREATE TABLE erp_budget_lines (id INTEGER PRIMARY KEY, condominium_id INTEGER, year INTEGER, chart_account_id INTEGER, month INTEGER, amount_cents INTEGER, updated_by INTEGER,
                UNIQUE (condominium_id, year, chart_account_id, month))',
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind, status, deleted_at) VALUES
            (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica', 'active', NULL),
            (4, NULL, '2.01', 'Limpeza', 'despesa', 'analitica', 'active', NULL),
            (5, NULL, '2.02', 'Inativa', 'despesa', 'analitica', 'inactive', NULL),
            (6, 3, '2.03', 'Do outro condomínio', 'despesa', 'analitica', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, status, deleted_at) VALUES
            (1, 'receita', 2, 'Taxa dez', 120000, '2025-12', 'active', NULL),
            (1, 'receita', 2, 'Taxa jun', 120000, '2026-06', 'active', NULL),
            (1, 'despesa', 4, 'Limpeza jan', 50000, '2026-01', 'active', NULL),
            (1, 'despesa', 4, 'Limpeza jul', 250005, '2026-07', 'active', NULL),
            (1, 'receita', 2, 'Antes do período', 99000, '2025-10', 'active', NULL),
            (1, 'receita', 2, 'Mês corrente', 98000, '2026-11', 'active', NULL),
            (1, 'despesa', 4, 'Cancelada', 97000, '2026-03', 'inactive', NULL),
            (1, 'despesa', 4, 'Excluída', 96000, '2026-03', 'active', '2026-04-01'),
            (1, 'despesa', 5, 'Conta inativa', 95000, '2026-03', 'active', NULL),
            (1, 'despesa', 6, 'Conta de outro condomínio', 94000, '2026-03', 'active', NULL),
            (3, 'despesa', 4, 'Outro condomínio', 93000, '2026-03', 'active', NULL)");
        $this->pdo->exec('INSERT INTO erp_budget_lines (condominium_id, year, chart_account_id, month, amount_cents) VALUES
            (1, 2027, 4, 1, 6000), (1, 2027, 4, 2, 6000), (3, 2027, 4, 1, 50000), (1, 2026, 4, 1, 40000)');
        $this->service = new BudgetProposal($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSuggestionFromLastTwelveMonths(): void
    {
        $p = $this->service->suggest($this->user(), 1, 2027, '10', 'flat', self::TODAY);
        self::assertSame(['2025-11', '2026-10', 10.0, 'flat'], [$p['base_from'], $p['base_to'], $p['adjustment_percent'], $p['distribution']]);
        $rows = array_column($p['rows'], null, 'chart_account_id');
        self::assertSame([2, 4], array_keys($rows), 'Só contas ativas do padrão/condomínio; cancelada, excluída, fora do período e outro condomínio não entram.');
        self::assertSame([240000, 264000, 0], [$rows[2]['base_cents'], $rows[2]['suggested_cents'], $rows[2]['current_budget_cents']]);
        self::assertSame(array_fill(1, 12, 22000), $rows[2]['suggested_months']);
        self::assertSame([300005, 330006, 12000], [$rows[4]['base_cents'], $rows[4]['suggested_cents'], $rows[4]['current_budget_cents']],
            'Previsto que já existe no ano (só deste condomínio e deste ano).');
        self::assertSame([27500, 27506], [$rows[4]['suggested_months'][1], $rows[4]['suggested_months'][12]], 'A sobra dos centavos vai para dezembro.');
        self::assertSame([264000, 330006, -66006], [$p['totals']['receita']['suggested_cents'], $p['totals']['despesa']['suggested_cents'], $p['totals']['result_cents']]);
        self::assertSame('deficit', $p['warnings'][0]['code']);
        self::assertSame(25.0, $p['warnings'][0]['revenue_increase_percent']);
        self::assertStringContainsString('25,00%', $p['warnings'][0]['message']);

        $s = $this->service->suggest($this->user(), 1, '2027', '-50', 'seasonal', self::TODAY);
        $rows = array_column($s['rows'], null, 'chart_account_id');
        self::assertSame([25000, 125003, 0], [$rows[4]['suggested_months'][1], $rows[4]['suggested_months'][7], $rows[4]['suggested_months'][3]],
            'Sazonal: cada mês segue o mesmo mês do período-base.');
        self::assertSame(150003, $rows[4]['suggested_cents']);
    }

    public function testApplyWritesThroughBudgetSettings(): void
    {
        $result = $this->service->apply($this->user(), 1, 2027, '10', 'flat', null, self::TODAY);
        self::assertSame([2, 4], $result['applied_accounts']);
        self::assertSame([[2, 12, 264000], [4, 12, 330006]], $this->lines(1, 2027));
        self::assertSame(50000, (int)$this->pdo->query('SELECT SUM(amount_cents) FROM erp_budget_lines WHERE condominium_id = 3')->fetchColumn(), 'Outro condomínio intacto.');
        self::assertCount(2, $result['budget']['rows']);

        $this->service->apply($this->user(), 1, 2027, 0, 'seasonal', ['2'], self::TODAY);
        self::assertSame([[2, 12, 240000], [4, 12, 330006]], $this->lines(1, 2027), 'Só a conta escolhida muda.');
    }

    public function testApplyRefusesBeforeWriting(): void
    {
        foreach ([[99], [2, 99], [], ['x']] as $ids) {
            try {
                $this->service->apply($this->user(), 1, 2027, 0, 'flat', $ids, self::TODAY);
                self::fail('Conta fora da sugestão passou: ' . json_encode($ids));
            } catch (InvalidArgumentException) {
                self::assertSame([[4, 2, 12000]], $this->lines(1, 2027), 'Nada gravado.');
            }
        }
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2027-03', 'reopened'), (3, '2027-04', 'closed')");
        $this->service->apply($this->user(), 1, 2027, 0, 'flat', ['4'], self::TODAY);   // reaberto e outro condomínio não travam
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2027-05', 'closed')");
        try {
            $this->service->apply($this->user(), 1, 2027, 10, 'flat', null, self::TODAY);
            self::fail('Ano com mês fechado passou.');
        } catch (DomainException) {
            self::assertSame([[4, 12, 300005]], $this->lines(1, 2027), 'Nada gravado.');
        }
    }

    public function testPureRulesScopeAndValidation(): void
    {
        self::assertSame(['2025-01', '2025-12'], BudgetProposal::basePeriod('2026-01-31'));
        self::assertSame(['2025-03', '2026-02'], BudgetProposal::basePeriod('2026-03-01'));
        self::assertSame(array_fill(1, 12, 0), BudgetProposal::distribute([3 => -500], 1.0, 'flat'), 'Estorno não vira previsto negativo.');
        self::assertSame([1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 8, 6 => 8, 7 => 8, 8 => 8, 9 => 8, 10 => 8, 11 => 8, 12 => 12], BudgetProposal::distribute([5 => 100], 1.0, 'flat'));
        try {
            $this->service->suggest($this->user(), 3, 2027, 0, 'flat', self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        foreach ([[null, 2027, 0, 'flat'], [1, 1999, 0, 'flat'], [1, 2032, 0, 'flat'], [1, 'x', 0, 'flat'], [1, 2027, 'abc', 'flat'], [1, 2027, -51, 'flat'],
            [1, 2027, 101, 'flat'], [1, 2027, 0, 'mensal']] as [$c, $y, $a, $d]) {
            try {
                $this->service->suggest($this->user(), $c, $y, $a, $d, self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $y, $a, $d]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(5.5, $this->service->suggest($this->user(), 1, 2027, '5,5', null, self::TODAY)['adjustment_percent'], 'Vírgula decimal; distribuição padrão flat.');
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/BudgetProposal.php');
        foreach (["Access::can('erp.settings.view', \$user)", "Access::can('erp.entries.view', \$user)", "Access::can('erp.settings.manage', \$user)",
            'catch (CondominiumAccessDenied)', 'JsonResponse::send(409', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $routes = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        self::assertStringContainsString('$route->get("/budgets/proposal", "BudgetProposal:show", null, \Source\Support\Middleware\ApiAuthenticated::class);', $routes);
        self::assertStringContainsString('$route->post("/budgets/proposal", "BudgetProposal:apply", null, \Source\Support\Middleware\ApiAuthenticated::class);', $routes);
    }

    /** @return list<array{0:int, 1:int, 2:int}> conta, meses gravados e total */
    private function lines(int $condominium, int $year): array
    {
        $stmt = $this->pdo->prepare('SELECT chart_account_id, COUNT(*), SUM(amount_cents) FROM erp_budget_lines WHERE condominium_id = ? AND year = ? GROUP BY chart_account_id ORDER BY chart_account_id');
        $stmt->execute([$condominium, $year]);
        return array_map(static fn (array $r): array => array_map('intval', $r), $stmt->fetchAll(PDO::FETCH_NUM));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
