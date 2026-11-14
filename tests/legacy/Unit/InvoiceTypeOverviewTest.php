<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceTypeOverview;
use Source\Services\Erp\Registry\RegistryService;

/**
 * ADR 0296: situação dos tipos de faturas, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2)
 * para a regra pura (linhas por recorrência com totais iguais à soma, padrões encobertos pelo tipo do condomínio, faturas
 * de tipos fora de uso à parte, tipos sem fatura, conta que não vale e o corte), a montagem sem nome de tipo, conta nem
 * observação (período nos limites, fatura cancelada, tipo excluído, padrão inativo e outro condomínio de fora), a
 * validação, o escopo e o contrato. As recorrências vêm do cadastro; nada de quantidade nem lista fixa. O MariaDB confere
 * com tipos de verdade (Integration\InvoiceTypeOverviewTest).
 */
final class InvoiceTypeOverviewTest extends TestCase
{
    private PDO $pdo;
    private InvoiceTypeOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_invoice_types (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, name TEXT, chart_account_id INTEGER, recurrence TEXT,
                default_amount_cents INTEGER NULL, notes TEXT, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_invoices (id INTEGER PRIMARY KEY, condominium_id INTEGER, invoice_type_id INTEGER, competence TEXT, status TEXT, amount_cents INTEGER, notes TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$m, $a] = self::recurrences();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $account = $this->pdo->prepare("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind, status) VALUES (?, ?, ?, 'Conta Secreta', ?, 'analitica', ?)");
        foreach ([[1, null, '1.01', 'receita', 'active'], [2, 1, '1.02', 'receita', 'inactive'], [3, 3, '1.03', 'receita', 'active'], [4, null, '2.01', 'despesa', 'active']] as $row) {
            $account->execute($row);
        }
        $type = $this->pdo->prepare("INSERT INTO erp_invoice_types (id, condominium_id, name, chart_account_id, recurrence, default_amount_cents, notes, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Observação reservada', ?, ?)");
        foreach ([[1, null, 'Taxa', 1, $m, 1000, 'active', null], [2, null, 'Fundo', 1, $m, null, 'active', null], [3, null, 'Obra', 4, $a, 500, 'active', null],
            [4, null, 'Velho', 1, $m, 100, 'inactive', null], [5, null, 'Extra', 1, $a, 200, 'active', '2026-11-01 10:00:00'], [6, 3, 'Fora', 3, $m, 100, 'active', null],
            [10, 1, 'Taxa', 1, $m, 1500, 'active', null], [11, 1, 'Rateio', 1, $a, null, 'inactive', null], [12, 1, 'Multa', 2, $a, 300, 'active', null],
            [13, 1, 'Jardim', 3, $m, 100, 'active', null], [14, null, 'Ghost', 1, $m, 50, 'active', null], [15, 1, 'Ghost', 1, $m, 50, 'active', '2026-11-02 10:00:00']] as $row) {
            $type->execute($row);
        }
        $invoice = $this->pdo->prepare("INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, status, amount_cents, notes) VALUES (?, ?, ?, ?, ?, 'Nota da Unidade 101')");
        foreach ([[1, 10, '2026-11', 'issued', 10000], [1, 10, '2026-10', 'draft', 5000], [1, 10, '2025-11', 'issued', 999], [1, 10, '2026-09', 'cancelled', 777],
            [1, 3, '2025-12', 'issued', 2000], [1, 11, '2026-05', 'issued', 300], [1, 1, '2026-01', 'issued', 400], [3, 6, '2026-11', 'issued', 123]] as $row) {
            $invoice->execute($row);
        }
        $this->service = new InvoiceTypeOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<string> as duas primeiras recorrências, vindas do cadastro */
    private static function recurrences(): array
    {
        $recurrences = array_keys(InvoiceTypeOverview::recurrences());
        self::assertGreaterThanOrEqual(2, count($recurrences));
        return array_slice($recurrences, 0, 2);
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$m, $a] = self::recurrences();
        $report = $this->service->build($this->user(), '1', '12', '2026-11-14');
        self::assertSame([1, 12, '2025-12', '2026-11'], [$report['condominium_id'], $report['months'], $report['from'], $report['to']]);
        self::assertSame(array_keys(InvoiceTypeOverview::recurrences()), array_column($report['rows'], 'recurrence'), 'Todas as recorrências, sempre.');
        $rows = array_combine(array_column($report['rows'], 'recurrence'), $report['rows']);
        $pick = static fn (array $r): array => [$r['available'], $r['own'], $r['standard'], $r['inactive_own'], $r['overridden_standard'], $r['unused'],
            $r['account_issue'], $r['without_amount'], $r['invoices'], $r['amount_cents']];
        self::assertSame([4, 2, 2, 0, 1, 3, 1, 1, 2, 15000], $pick($rows[$m]),
            'O padrão encoberto pelo tipo do condomínio sai; o encoberto só por um excluído fica; sem a fatura fora do período e a cancelada.');
        self::assertSame([2, 1, 1, 1, 0, 1, 2, 0, 1, 2000], $pick($rows[$a]), 'Conta de despesa e conta inativa não valem.');
        foreach (array_keys($rows[$m]) as $key) {
            if (is_int($rows[$m][$key])) {
                self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
            }
        }
        self::assertSame(['invoices' => 2, 'amount_cents' => 700], $report['elsewhere'], 'Faturas de tipo inativo ou encoberto, à parte.');
        self::assertSame([5, 17700], [$report['all_invoices'], $report['all_amount_cents']]);
        self::assertSame($report['all_invoices'], $report['totals']['invoices'] + $report['elsewhere']['invoices']);
        self::assertSame([[2, 12, 13, 14], false], [$report['unused_ids'], $report['unused_truncated']]);
        self::assertSame([[3, 12, 13], false], [$report['account_issue_ids'], $report['account_issue_truncated']], 'A conta de outro condomínio também não vale.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Taxa', 'Fundo', 'Multa', 'Jardim', 'Conta Secreta', 'Observação', 'Unidade', 'name', 'notes', 'code'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testCutAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= InvoiceTypeOverview::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'standard' => false, 'recurrence' => self::recurrences()[0], 'status' => 'active', 'overridden' => false, 'account_ok' => false, 'has_amount' => true];
        }
        $report = InvoiceTypeOverview::summarize($many, []);
        self::assertCount(InvoiceTypeOverview::MAX_IDS, $report['unused_ids']);
        self::assertCount(InvoiceTypeOverview::MAX_IDS, $report['account_issue_ids']);
        self::assertSame([true, true], [$report['unused_truncated'], $report['account_issue_truncated']]);
        self::assertSame(InvoiceTypeOverview::MAX_IDS + 1, $report['totals']['available'], 'A contagem não é cortada.');
        $empty = InvoiceTypeOverview::summarize([], [9 => ['count' => 3, 'amount_cents' => 450]]);
        self::assertSame([count(InvoiceTypeOverview::recurrences()), 0, 3, 450], [count($empty['rows']), $empty['totals']['available'], $empty['all_invoices'], $empty['all_amount_cents']],
            'Sem tipo à mão, as faturas ficam à parte.');
    }

    public function testValidationAndScope(): void
    {
        foreach ([[null, null], ['', null], [0, null], ['abc', null], [1, '0'], [1, 'x'], [1, (string)(InvoiceTypeOverview::MAX_MONTHS + 1)], [[], null]] as [$c, $n]) {
            try {
                $this->service->build($this->user(), $c, $n, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode([$c, $n]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, null, '2026-11-14');
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $definition = RegistryService::definition('invoice_types');
        self::assertSame(['erp_invoice_types', 'name', 'optional', 'erp_chart_accounts'], [$definition['table'], $definition['override'], $definition['scope'],
            RegistryService::definition('chart')['table']]);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0513_invoices.sql');
        self::assertMatchesRegularExpression("/CREATE TABLE IF NOT EXISTS `erp_invoices`.*?`status` enum\\('draft','issued','cancelled'\\)/s", $sql, 'Cancelada é cancelled.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/InvoiceTypeOverview.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('invoice_types')", "RegistryService::definition('chart')", 'SELECT NOW()',
            'DiscountOverview::months(', "status <> 'cancelled'", "c.kind = 'analitica' AND c.nature = 'receita'", 'o.deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 't.name', 'c.name', 'notes', 'SELECT *', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem nome, conta, observação ou unidade: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/InvoiceTypeOverview.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.invoices.view', \$user)", "['invoice_types', 'chart']",
            "RegistryService::definition(\$slug)['permission'] . '.view'", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/invoice-types", "InvoiceTypeOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/gatehouses/deactivate-idle"'), strpos($index, $route), 'Logo depois do lote de portarias.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /gatehouses/deactivate-idle:\n"), strpos($openapi, "  /reports/invoice-types:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
