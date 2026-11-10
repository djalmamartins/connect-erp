<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AnnualBalance;
use Source\Services\Erp\CollectionReports;

/** Balancete Anual (AP-080, ADR 0164): regra pura, consulta por competência ou caixa, escopo e CSV. */
final class AnnualBalanceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, amount_cents INTEGER, competence TEXT,
                due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica'), (5, NULL, '2.10', 'Limpeza', 'despesa', 'analitica')");
        $insert = $this->pdo->prepare('INSERT INTO erp_entries (condominium_id, nature, chart_account_id, amount_cents, competence, due_on, paid_on, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 'receita', 2, 100000, '2026-01', '2026-01-10', '2026-02-05', 'active', null],   // competência jan, pago em fev
            [1, 'receita', 2, 100000, '2026-02', '2026-02-10', null, 'active', null],           // em aberto
            [1, 'despesa', 4, 30000, '2026-01', '2026-01-15', '2026-01-15', 'active', null],
            [1, 'despesa', 5, 20000, '2026-12', '2026-12-15', '2026-12-15', 'active', null],
            [1, 'despesa', 4, 99999, '2026-03', '2026-03-15', null, 'inactive', null],          // cancelado: não entra
            [1, 'despesa', 4, 88888, '2026-03', '2026-03-15', null, 'active', '2026-03-20'],    // excluído: não entra
            [1, 'receita', 2, 77777, '2025-12', '2025-12-10', '2026-01-03', 'active', null],    // competência do ano anterior
            [2, 'receita', 2, 50000, '2026-01', '2026-01-10', '2026-01-10', 'active', null],
            [3, 'receita', 2, 999999, '2026-01', '2026-01-10', '2026-01-10', 'active', null],   // fora do escopo
        ] as $row) {
            $insert->execute($row);
        }
    }

    public function testPureBuildRollsUpMonthsAndTotals(): void
    {
        $report = AnnualBalance::build([
            ['code' => '1.01', 'name' => 'Taxa', 'nature' => 'receita', 'month' => '2026-01', 'cents' => '1000'],
            ['code' => '1.01', 'name' => 'Taxa', 'nature' => 'receita', 'month' => '2026-03', 'cents' => 500],
            ['code' => '2.10', 'name' => 'Limpeza', 'nature' => 'despesa', 'month' => '2026-03', 'cents' => 300],
            ['code' => '2.9', 'name' => 'Água', 'nature' => 'despesa', 'month' => '2026-12', 'cents' => 100],
            ['code' => '2.9', 'name' => 'Água', 'nature' => 'despesa', 'month' => '2025-12', 'cents' => 99999],
            ['code' => '9', 'name' => 'X', 'nature' => 'outra', 'month' => '2026-01', 'cents' => 99999],
        ], 2026, ['1' => 'Receitas', '2' => 'Despesas']);
        self::assertSame(['2026-01', '2026-12'], [$report['months'][0], $report['months'][11]]);
        self::assertSame(['1', '1.01', '2', '2.9', '2.10'], array_column($report['rows'], 'code'), '"2.9" antes de "2.10"; receita antes de despesa.');
        self::assertSame(['Receitas', 1, false], [$report['rows'][0]['name'], $report['rows'][0]['level'], $report['rows'][0]['analytic']]);
        self::assertSame([1000, 0, 500, 0, 0, 0, 0, 0, 0, 0, 0, 0], $report['rows'][0]['months_cents']);
        self::assertSame(1500, $report['rows'][0]['total_cents']);
        self::assertSame(400, $report['rows'][2]['total_cents'], 'A sintética soma as analíticas; mês de outro ano fica fora.');
        self::assertSame([1000, 0, 200, 0, 0, 0, 0, 0, 0, 0, 0, -100], $report['totals']['result_months_cents']);
        self::assertSame([1500, 400, 1100], [$report['totals']['revenue_cents'], $report['totals']['expense_cents'], $report['totals']['result_cents']]);
    }

    public function testCompetenceAndCashBasesWithScope(): void
    {
        $service = new AnnualBalance($this->pdo, new CondominiumScope($this->pdo));
        $competence = $service->report($this->user(), 1, '2026');
        self::assertSame(['condominium_id' => 1, 'year' => 2026, 'basis' => 'competence', 'truncated' => false], array_slice($competence, 0, 4));
        self::assertSame([200000, 50000, 150000], [$competence['totals']['revenue_cents'], $competence['totals']['expense_cents'], $competence['totals']['result_cents']],
            'Competência: previsto do ano, sem cancelado, excluído nem competência de outro ano.');
        $cash = $service->report($this->user(), 1, 2026, 'cash');
        self::assertSame(177777, $cash['totals']['revenue_cents'], 'Caixa: pelo mês do pagamento (inclui o pago em janeiro da competência de dezembro).');
        self::assertSame([77777, 100000], [$cash['totals']['revenue_months_cents'][0], $cash['totals']['revenue_months_cents'][1]]);
        $all = $service->report($this->user(), null, 2026);
        self::assertSame(250000, $all['totals']['revenue_cents'], 'Todos = só os condomínios do escopo (1 e 2), nunca o 3.');
        $this->expectException(CondominiumAccessDenied::class);   // o controller responde 404
        $service->report($this->user(), 3, 2026);
    }

    public function testValidationAndCsv(): void
    {
        foreach (['', '26', '1999', '2101', 'abc'] as $bad) {
            try {
                AnnualBalance::year($bad);
                self::fail('Aceitou o ano ' . $bad);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectExceptionObject(new InvalidArgumentException('Base inválida: use competence ou cash.'));
        try {
            $report = (new AnnualBalance($this->pdo, new CondominiumScope($this->pdo)))->report($this->user(), 1, 2026);
            $csv = CollectionReports::csv(AnnualBalance::csvTable($report));
            self::assertStringStartsWith("\xEF\xBB\xBFCódigo;Conta;Natureza;Jan;Fev;Mar;Abr;Mai;Jun;Jul;Ago;Set;Out;Nov;Dez;Total\r\n", $csv);
            self::assertStringContainsString('1.01;"  Taxa condominial";Receita;1000,00;1000,00;0,00', $csv);
            self::assertStringContainsString(';Resultado;;700,00;1000,00;', $csv);
            $evil = AnnualBalance::csvTable(['rows' => [['code' => '=1', 'name' => '=HYPERLINK("x")', 'nature' => 'receita', 'level' => 1,
                'months_cents' => array_fill(0, 12, 0), 'total_cents' => 0]], 'totals' => $report['totals']]);
            self::assertSame(["'=1", "'=HYPERLINK(\"x\")"], array_slice($evil['rows'][0], 0, 2), 'Fórmula no CSV é neutralizada.');
            $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/AnnualBalance.php');
            foreach (["'erp.entries.view'", "'erp.chart.view'", '(new ReportExportLimit())->assertAllowed($user)', 'catch (CondominiumAccessDenied)'] as $needle) {
                self::assertStringContainsString($needle, $source, $needle);
            }
            self::assertStringContainsString('LIMIT " . (self::MAX_GROUPS + 1)', (string)file_get_contents(dirname(__DIR__, 3) . '/source/Services/Erp/AnnualBalance.php'));
        } finally {
            AnnualBalance::basis('mensal');
        }
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
