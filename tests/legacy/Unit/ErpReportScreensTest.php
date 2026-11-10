<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\ReportScreens as Screens;
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\AnnualBalance;
use Source\Services\Erp\ManagementReports;
use Source\Services\Erp\PaymentReports;
use Source\Services\Erp\RegistryReports;

/**
 * Tela única dos relatórios com serviço e API (ADR 0143): tela própria só com dados, permissões iguais às
 * da API, colunas que existem no CSV do serviço, filtros com padrão e o endereço do CSV.
 */
final class ErpReportScreensTest extends TestCase
{
    public function testPagesTemplateAndPermissions(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (Screens::PAGES as $page => $definition) {
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame('pages/reports/report', Panel::ownTemplate($page, ['reportReady' => true]));
            self::assertSame($definition['perms'][0], $permissions[$page]);
            self::assertNotSame([], $definition['columns']);
        }
        self::assertSame(['erp.notifications.view', 'erp.bank_charges.view'], Screens::PAGES['AP-099']['perms']);
        self::assertSame(['erp.entries.view', 'erp.chart.view'], Screens::PAGES['AP-080']['perms']);
    }

    public function testColumnsAreInTheCsvExport(): void
    {
        foreach (Screens::PAGES as $page => $definition) {
            if (($definition['csv'] ?? true) === false) {
                continue;   // AP-084: a API de previsão não exporta CSV
            }
            $kind = $definition['api'] . match (true) {
                $definition['api'] === 'financial-statement' => '',
                $definition['api'] === 'apportionment' => '-' . $definition['query']['view'],
                isset($definition['query']['detailed']) => '-detailed',
                default => '',
            };
            $header = match (true) {
                in_array($definition['api'], ['ledger', 'account-balances'], true) => AccountingReports::csvTable($definition['api'], ['accounts' => [], 'totals' => ['opening_cents' => 0, 'received_cents' => 0, 'paid_cents' => 0, 'closing_cents' => 0]])['header'],
                $definition['api'] === 'income-statement' => AccountingReports::csvTable('income-statement', ['rows' => [], 'totals' => ['revenue_cents' => 0, 'expense_cents' => 0, 'result_cents' => 0]])['header'],
                $definition['api'] === 'annual-balance' => AnnualBalance::csvTable(['rows' => [], 'totals' => ['revenue_months_cents' => [], 'expense_months_cents' => [], 'result_months_cents' => [], 'revenue_cents' => 0, 'expense_cents' => 0, 'result_cents' => 0]])['header'],
                in_array($definition['api'], ['payments', 'calculation', 'transfers'], true) => PaymentReports::csvTable($kind, ['rows' => []])['header'],
                $definition['api'] === 'financial-statement' => ManagementReports::csvTable('statement-' . $definition['query']['view'], ['rows' => []])['header'],
                in_array($definition['api'], ['consumption', 'cost-centers', 'comparison', 'account-statement', 'apportionment', 'entries', 'bank-charges'], true) => ManagementReports::csvTable($kind, ['rows' => []])['header'],
                default => RegistryReports::csvTable($kind, ['rows' => []])['header'],
            };
            self::assertLessThanOrEqual(count($header), count($definition['columns']), "{$page}: a tela não mostra mais colunas que o CSV");
        }
    }

    public function testFiltersDefaultsAndCsvUrl(): void
    {
        $f = Screens::filters('AP-068', ['de' => '2026-13', 'ate' => '2026-05', 'natureza' => 'x', 'situacao' => 'overdue'], '2026-11-10');
        self::assertSame(['2026-01', '2026-05', '', 'overdue'], [$f['de'], $f['ate'], $f['natureza'], $f['situacao']]);
        $f = Screens::filters('AP-070', ['de' => '2026-02-30'], '2026-11-10');
        self::assertSame(['2026-01-01', '2026-11-10', '2026-12-10'], [$f['de'], $f['ate'], $f['limite']]);
        self::assertSame('2026-10', Screens::filters('AP-063', [], '2026-11-10')['comparar']);
        self::assertNull(Screens::filters('AP-062', [], '2026-11-10')['comparar']);
        self::assertSame(2026, Screens::filters('AP-080', ['ano' => '1999'], '2026-11-10')['ano']);
        self::assertSame('despesa', Screens::filters('AP-074', [], '2026-11-10')['pagamento']);
        self::assertSame('/api/v1/reports/entries?format=csv&condominium_id=2&detailed=1&from=2026-01&to=2026-11&situation=overdue',
            Screens::csvUrl('AP-069', Screens::filters('AP-069', ['situacao' => 'overdue'], '2026-11-10'), 2));
        self::assertSame('/api/v1/reports/account-statement?format=csv&bank_account_id=3&from=2026-01-01&to=2026-11-10',
            Screens::csvUrl('AP-056', Screens::filters('AP-056', ['conta' => '3'], '2026-11-10'), null));
        self::assertSame('/api/v1/reports/annual-balance?format=csv&year=2025&basis=cash', Screens::csvUrl('AP-080', Screens::filters('AP-080', ['ano' => '2025', 'base' => 'cash'], '2026-11-10'), null));
    }

    public function testLedgerRowsAndAccountingUrls(): void
    {
        $rows = Screens::ledgerRows([['code' => '2.01', 'name' => 'Limpeza', 'nature' => 'despesa', 'opening_cents' => 500, 'debit_cents' => 100, 'credit_cents' => 0, 'closing_cents' => 600,
            'lines' => [['entry_id' => 9, 'on_date' => '2026-11-05', 'competence' => '2026-11', 'description' => 'Faxina', 'debit_cents' => 100, 'credit_cents' => 0, 'balance_cents' => 600]]]]);
        self::assertSame(['Saldo anterior', 'Faxina', 'Saldo final'], array_column($rows, 'description'));
        self::assertSame([500, 600, 600], array_column($rows, 'balance_cents'));
        self::assertSame(['erp-report-synthetic', '', 'erp-report-synthetic'], array_map([Screens::class, 'rowClass'], $rows));
        self::assertSame('/api/v1/reports/ledger?format=csv&condominium_id=1&from=2026-01-01&to=2026-11-10&basis=cash&account_code=2.01',
            Screens::csvUrl('AP-083', Screens::filters('AP-083', ['base' => 'cash', 'codigo' => '2.01'], '2026-11-10'), 1));
        self::assertSame('', Screens::filters('AP-083', ['codigo' => '2;DROP'], '2026-11-10')['codigo']);
        self::assertSame('/api/v1/reports/account-balances?format=csv&as_of=2026-11-10', Screens::csvUrl('AP-073', Screens::filters('AP-073', [], '2026-11-10'), null));
        self::assertSame([['Saldo anterior', 1], ['Entradas', 2], ['Saídas', 3], ['Saldo final', 0]],
            Screens::totals(['totals' => ['opening_cents' => 1, 'received_cents' => 2, 'paid_cents' => 3, 'closing_cents' => 0]], Screens::PAGES['AP-073']['totalLabels']));
    }

    public function testCellsAndTotals(): void
    {
        self::assertSame('−R$ 1.234,56', Screens::cell(['v' => -123456], ['V', 'v', 'money']));
        self::assertSame('05/10/2026 14:30', Screens::cell(['v' => '2026-10-05 14:30:00'], ['V', 'v', 'datetime']));
        self::assertSame('&lt;b&gt;', Screens::cell(['v' => '<b>'], ['V', 'v', 'text']));
        self::assertSame('<span class="erp-muted">—</span>', Screens::cell(['v' => null], ['V', 'v', 'money']));
        self::assertSame('<span class="erp-badge erp-badge--danger">Vencido</span>', Screens::cell(['v' => 'overdue'], ['V', 'v', 'label:situation']));
        self::assertSame('R$ 5,00', Screens::cell(['m' => [0, 500]], ['V', 'm.1', 'money']));
        self::assertSame('12,5', Screens::cell(['v' => 12.5], ['V', 'v', 'number']));
        self::assertSame([['Saldo anterior', 100], ['Entradas', 50], ['Saldo final', 150]], Screens::totals(['opening_cents' => 100, 'totals' => ['in_cents' => 50, 'closing_cents' => 150, 'by_status' => []]]));
        self::assertSame('erp-report-synthetic', Screens::rowClass(['analytic' => false]));
        self::assertSame('erp-muted', Screens::rowClass(['status' => 'cancelled']));
    }
}
