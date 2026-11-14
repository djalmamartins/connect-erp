<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceReconciliation;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryService;

/**
 * ADR 0400: conferência entre as faturas e as contas a receber, somente leitura. Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2) para a regra pura (as classes do item por situação da fatura, o total gravado, a preparação
 * vencida, as faturas que pedem atenção e o corte), a montagem sem unidade, descrição nem morador (período nos limites,
 * conta excluída, outro condomínio de fora), a validação, o escopo e o contrato. As situações vêm de Invoices::STATUSES;
 * nada de quantidade nem lista fixa. O MariaDB confere com faturas emitidas de verdade
 * (Integration\InvoiceReconciliationTest).
 */
final class InvoiceReconciliationTest extends TestCase
{
    private PDO $pdo;
    private InvoiceReconciliation $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_invoices (id INTEGER PRIMARY KEY, condominium_id INTEGER, invoice_type_id INTEGER, competence TEXT, due_on TEXT, status TEXT,
                units_count INTEGER, amount_cents INTEGER, notes TEXT)',
            'CREATE TABLE erp_invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, unit_id INTEGER, unit_label TEXT, amount_cents INTEGER)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, invoice_item_id INTEGER NULL, description TEXT, amount_cents INTEGER,
                paid_on TEXT NULL, agreement_id INTEGER NULL, status TEXT, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $invoice = $this->pdo->prepare("INSERT INTO erp_invoices (id, condominium_id, invoice_type_id, competence, due_on, status, units_count, amount_cents, notes)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, 'Nota Reservada')");
        foreach ([[1, 1, '2026-10', '2026-10-10', 'issued', 6, 600], [2, 1, '2026-09', '2026-09-10', 'issued', 1, 100], [3, 1, '2026-08', '2026-08-10', 'cancelled', 2, 200],
            [4, 1, '2026-11', '2026-11-01', 'draft', 2, 300], [5, 1, '2026-11', '2026-11-20', 'draft', 1, 100], [6, 1, '2025-11', '2025-11-10', 'issued', 1, 100],
            [7, 3, '2026-10', '2026-10-10', 'issued', 1, 100], [8, 1, '2025-12', '2025-12-10', 'issued', 1, 100]] as $row) {
            $invoice->execute($row);
        }
        $item = $this->pdo->prepare("INSERT INTO erp_invoice_items (id, invoice_id, unit_id, unit_label, amount_cents) VALUES (?, ?, ?, 'Bloco Secreto 101', 100)");
        foreach ([[1, 1, 1], [2, 1, 2], [3, 1, 3], [4, 1, 4], [5, 1, 5], [6, 1, 6], [7, 2, 1], [8, 3, 1], [9, 3, 2], [10, 4, 1], [11, 4, 2], [12, 5, 1], [13, 6, 1],
            [14, 7, 1], [15, 8, 1]] as $row) {
            $item->execute($row);
        }
        $entry = $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, invoice_item_id, description, amount_cents, paid_on, agreement_id, status, deleted_at)
            VALUES (?, ?, 'Taxa Reservada', ?, ?, ?, ?, ?)");
        foreach ([[1, 1, 100, null, null, 'active', null], [1, 2, 100, '2026-10-15', null, 'active', null], [1, 4, 100, null, null, 'active', '2026-10-20 10:00:00'],
            [1, 5, 100, null, 9, 'inactive', null], [1, 6, 150, null, null, 'active', null], [1, 7, 100, null, null, 'inactive', null],
            [1, 8, 100, null, null, 'inactive', null], [1, 9, 100, null, null, 'active', null], [3, 14, 100, null, null, 'active', null],
            [1, 15, 100, null, null, 'active', null]] as $row) {
            $entry->execute($row);
        }
        $this->service = new InvoiceReconciliation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testClassifyRule(): void
    {
        $entry = ['status' => 'active', 'amount_cents' => 100, 'paid' => false, 'agreement' => false];
        self::assertSame('open', InvoiceReconciliation::classify('issued', 100, $entry));
        self::assertSame('paid', InvoiceReconciliation::classify('issued', 100, ['paid' => true] + $entry));
        self::assertSame('amount_mismatch', InvoiceReconciliation::classify('issued', 99, $entry));
        self::assertSame('inactive', InvoiceReconciliation::classify('issued', 100, ['status' => 'inactive'] + $entry));
        self::assertSame('renegotiated', InvoiceReconciliation::classify('issued', 99, ['status' => 'inactive', 'agreement' => true] + $entry), 'O acordo vem antes.');
        self::assertSame('missing', InvoiceReconciliation::classify('issued', 100, null));
        self::assertSame('still_active', InvoiceReconciliation::classify('cancelled', 100, $entry));
        self::assertNull(InvoiceReconciliation::classify('cancelled', 100, ['status' => 'inactive'] + $entry));
        self::assertNull(InvoiceReconciliation::classify('draft', 100, null), 'Na preparação ainda não há conta.');
    }

    public function testSummaryFromTheCondominium(): void
    {
        $report = $this->service->build($this->user(), '1', '12', '2026-11-14');
        self::assertSame([1, 12, '2025-12', '2026-11'], [$report['condominium_id'], $report['months'], $report['from'], $report['to']]);
        self::assertSame(array_keys(Invoices::STATUSES), array_column($report['rows'], 'status'), 'Todas as situações, sempre.');
        $rows = array_combine(array_column($report['rows'], 'status'), $report['rows']);
        $pick = static fn (array $r): array => [$r['invoices'], $r['items'], $r['amount_cents'], $r['total_mismatch'], $r['overdue_drafts'], $r['missing'],
            $r['renegotiated'], $r['inactive'], $r['amount_mismatch'], $r['paid'], $r['open'], $r['still_active']];
        self::assertSame([3, 8, 800, 0, 0, 2, 1, 1, 1, 1, 2, 0], $pick($rows['issued']), 'A conta excluída conta como sem conta; a fatura de antes do período fica de fora.');
        self::assertSame([1, 2, 200, 0, 0, 0, 0, 0, 0, 0, 0, 1], $pick($rows['cancelled']));
        self::assertSame([2, 3, 300, 1, 1, 0, 0, 0, 0, 0, 0, 0], $pick($rows['draft']), 'O total gravado que não bate e a preparação vencida.');
        foreach (array_keys($report['totals']) as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        self::assertSame([[1, 2, 3, 4], false], [$report['attention_ids'], $report['attention_truncated']], 'Pago, em aberto e renegociado não pedem atenção.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Bloco', 'Secreto', 'Reservad', 'unit', 'description', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testCutAndEmpty(): void
    {
        $invoices = [];
        for ($i = 1; $i <= InvoiceReconciliation::MAX_IDS + 1; $i++) {
            $invoices[] = ['id' => $i, 'status' => 'issued', 'due_on' => '2026-10-10', 'units_count' => 1, 'amount_cents' => 100];
        }
        $report = InvoiceReconciliation::summarize($invoices, [], '2026-11-14');
        self::assertSame([InvoiceReconciliation::MAX_IDS, true], [count($report['attention_ids']), $report['attention_truncated']]);
        self::assertSame(InvoiceReconciliation::MAX_IDS + 1, $report['totals']['total_mismatch'], 'A contagem não é cortada.');
        $empty = InvoiceReconciliation::summarize([], [], '2026-11-14');
        self::assertSame([count(Invoices::STATUSES), 0, []], [count($empty['rows']), $empty['totals']['invoices'], $empty['attention_ids']]);
    }

    public function testValidationAndScope(): void
    {
        foreach ([[null, null], ['', null], [0, null], ['abc', null], [1, '0'], [1, 'x'], [1, (string)(InvoiceReconciliation::MAX_MONTHS + 1)], [[], null]] as [$c, $m]) {
            try {
                $this->service->build($this->user(), $c, $m, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m]));
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
        self::assertSame('erp_entries', RegistryService::definition('entries')['table']);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0514_entry_unit_invoice_link.sql');
        self::assertStringContainsString('ADD UNIQUE INDEX IF NOT EXISTS `uq_erp_entries_invoice_item` (`invoice_item_id`)', $sql, 'Uma conta por item.');
        $invoices = (string)file_get_contents($root . '/source/Services/Erp/Invoices.php');
        self::assertStringContainsString("SET e.status = 'inactive', e.updated_by = :u WHERE it.invoice_id = :id", $invoices, 'Cancelar inativa as contas da fatura.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/InvoiceReconciliation.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('entries')", 'Invoices::STATUSES', 'DiscountOverview::months(', 'SELECT NOW()',
            'e.invoice_item_id = it.id AND e.deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'unit_label', 'description', 'notes', 'bank_account', 'SELECT *'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem unidade, descrição ou conta bancária: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/InvoiceReconciliation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.invoices.view', \$user)", "RegistryService::definition('entries')['permission'] . '.view'",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/invoice-reconciliation", "InvoiceReconciliation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/meters/deactivate-idle"'), strpos($index, $route), 'Logo depois do lote de relógios.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /meters/deactivate-idle:\n"), strpos($openapi, "  /reports/invoice-reconciliation:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
