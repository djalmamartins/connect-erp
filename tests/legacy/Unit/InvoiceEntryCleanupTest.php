<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceEntryCleanup;
use Source\Services\Erp\InvoiceReconciliation;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0401: contas ativas de faturas canceladas inativadas em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas (uma por caso, tudo
 * desfeito), a prévia, a inativação só das contas sem pagamento, acordo, ligação ou competência fechada (as outras contadas
 * à parte), sem apagar nem tocar fatura emitida, a conferência da 0400 depois e o contrato. O MariaDB confere com
 * faturas de verdade (Integration\InvoiceEntryCleanupTest).
 */
final class InvoiceEntryCleanupTest extends TestCase
{
    private PDO $pdo;
    private InvoiceEntryCleanup $service;

    protected function setUp(): void
    {
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = ['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE erp_invoices (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, due_on TEXT, status TEXT, units_count INTEGER, amount_cents INTEGER)',
            'CREATE TABLE erp_invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, unit_id INTEGER, unit_label TEXT, amount_cents INTEGER)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, invoice_item_id INTEGER NULL, description TEXT, amount_cents INTEGER, competence TEXT,
                paid_on TEXT NULL, agreement_id INTEGER NULL, status TEXT, updated_by INTEGER, deleted_at TEXT NULL)'];
        foreach (InvoiceEntryCleanup::LINKS as $table) {
            $tables[] = "CREATE TABLE {$table} (id INTEGER PRIMARY KEY, entry_id INTEGER)";
        }
        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-08', 'closed'), (1, '2026-10', 'reopened')");
        $invoice = $this->pdo->prepare('INSERT INTO erp_invoices (id, condominium_id, competence, due_on, status, units_count, amount_cents) VALUES (?, ?, ?, ?, ?, 1, 100)');
        foreach ([[1, 1, '2026-10', '2026-10-10', 'cancelled'], [2, 1, '2026-10', '2026-10-10', 'issued'], [3, 3, '2026-10', '2026-10-10', 'cancelled'],
            [4, 1, '2026-08', '2026-08-10', 'cancelled']] as $row) {
            $invoice->execute($row);
        }
        $item = $this->pdo->prepare("INSERT INTO erp_invoice_items (id, invoice_id, unit_id, unit_label, amount_cents) VALUES (?, ?, 1, 'Bloco Secreto 101', 100)");
        foreach ([[1, 1], [2, 1], [3, 1], [4, 1], [5, 1], [6, 1], [7, 1], [8, 2], [9, 3], [10, 4], [11, 1]] as $row) {
            $item->execute($row);
        }
        $entry = $this->pdo->prepare("INSERT INTO erp_entries (id, condominium_id, invoice_item_id, description, amount_cents, competence, paid_on, agreement_id, status, updated_by, deleted_at)
            VALUES (?, ?, ?, 'Taxa Reservada', 100, ?, ?, ?, ?, 3, ?)");
        foreach ([[1, 1, 1, '2026-10', null, null, 'active', null], [2, 1, 2, '2026-10', null, null, 'active', null], [3, 1, 3, '2026-10', '2026-10-15', null, 'active', null],
            [4, 1, 4, '2026-10', null, 5, 'active', null], [5, 1, 5, '2026-10', null, null, 'inactive', null], [6, 1, 6, '2026-10', null, null, 'active', '2026-10-20 10:00:00'],
            [7, 1, 7, '2026-10', null, null, 'active', null], [8, 1, 8, '2026-10', null, null, 'active', null], [9, 3, 9, '2026-10', null, null, 'active', null],
            [10, 1, 10, '2026-08', null, null, 'active', null], [11, 1, 11, '2026-10', null, null, 'active', null]] as $row) {
            $entry->execute($row);
        }
        $this->pdo->exec('INSERT INTO erp_bank_charges (entry_id) VALUES (2)');
        $this->pdo->exec('INSERT INTO erp_legal_case_entries (entry_id) VALUES (7)');
        $this->service = new InvoiceEntryCleanup($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemRule(): void
    {
        $row = ['status' => 'active', 'paid' => false, 'agreement' => false];
        self::assertNull(InvoiceEntryCleanup::problem($row, false, false));
        self::assertNotNull(InvoiceEntryCleanup::problem($row, true, false));
        self::assertNotNull(InvoiceEntryCleanup::problem($row, false, true));
        self::assertNotNull(InvoiceEntryCleanup::problem(['agreement' => true] + $row, false, false));
        self::assertNotNull(InvoiceEntryCleanup::problem(['paid' => true] + $row, false, false));
        self::assertNotNull(InvoiceEntryCleanup::problem(['status' => 'inactive'] + $row, false, false));
        self::assertNotNull(InvoiceEntryCleanup::problem(null, false, false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Fatura cancelada'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['entry_ids' => []] + $ok, ['entry_ids' => 'x'] + $ok, ['entry_ids' => ['a']] + $ok, ['entry_ids' => range(1, InvoiceEntryCleanup::MAX_ENTRIES + 1)] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('active', $this->entryStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->deactivate($this->user(), ['condominium_id' => 3] + $ok);
    }

    public function testDryRunThenDeactivateTheSafeOnes(): void
    {
        $before = InvoiceReconciliation::summarize([['id' => 1, 'status' => 'cancelled', 'due_on' => '2026-10-10', 'units_count' => 1, 'amount_cents' => 100]],
            [['invoice_id' => 1, 'amount_cents' => 100, 'entry' => ['status' => 'active', 'amount_cents' => 100, 'paid' => false, 'agreement' => false]]], '2026-11-14');
        self::assertSame([1], $before['attention_ids'], 'O caso que a conferência da 0400 aponta.');

        $input = ['condominium_id' => '1', 'reason' => 'Fatura cancelada', 'dry_run' => true];
        $preview = $this->service->deactivate($this->user(), $input);
        self::assertSame([[1, 11], 1, 3, 1, 0, true, 1], [array_column($preview['entries'], 'id'), $preview['skipped_paid'], $preview['skipped_linked'],
            $preview['skipped_closed'], $preview['changed'], $preview['dry_run'], $preview['invoices']],
            'A paga, a em acordo, a com boleto, a em cobrança judicial e a de competência fechada ficam de fora e são contadas.');
        self::assertSame('active', $this->entryStatus(1), 'A prévia desfaz.');

        $done = $this->service->deactivate($this->user(), ['dry_run' => false] + $input);
        self::assertSame([2, 2], [$done['count'], $done['changed']]);
        self::assertSame(['inactive', 'inactive'], [$this->entryStatus(1), $this->entryStatus(11)]);
        foreach ([2, 3, 4, 7, 8, 9, 10] as $id) {
            self::assertSame('active', $this->entryStatus($id), "Conta {$id}: boleto, paga, acordo, judicial, fatura emitida, outro condomínio ou fechada ficam.");
        }
        self::assertSame([7, 3], [(int)$this->pdo->query('SELECT updated_by FROM erp_entries WHERE id = 1')->fetchColumn(),
            (int)$this->pdo->query('SELECT updated_by FROM erp_entries WHERE id = 2')->fetchColumn()]);
        self::assertSame([11, 4, 1], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_invoices')->fetchColumn(),
            (int)$this->pdo->query("SELECT COUNT(*) FROM erp_invoices WHERE status = 'issued'")->fetchColumn()], 'Nada é apagado e nenhuma fatura muda.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Bloco', 'Secreto', 'Reservad', 'cancelada', 'amount', 'unit', 'description', 'bank'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach ([2 => 'ligada', 3 => 'paga', 4 => 'ligada', 7 => 'ligada', 5 => 'já está inativa', 10 => 'fechada', 6 => 'não encontrada', 8 => 'não encontrada',
            9 => 'não encontrada', 999 => 'não encontrada'] as $bad => $text) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Fatura cancelada', 'entry_ids' => [1, $bad]]);
                self::fail("A conta {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_entry']);
                self::assertStringContainsString($text, $exception->errors['_form']);
            }
            self::assertSame('active', $this->entryStatus(1), 'Tudo ou nada.');
        }
        $done = $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Fatura cancelada', 'entry_ids' => ['11']]);
        self::assertSame([[11], 'active'], [array_column($done['entries'], 'id'), $this->entryStatus(1)], 'Com ids, só as informadas.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame('erp_entries', RegistryService::definition('entries')['table']);
        $migrations = '';
        foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
            if (!str_ends_with($file, '.down.sql')) {
                $migrations .= (string)file_get_contents($file);
            }
        }
        foreach (InvoiceEntryCleanup::LINKS as $table) {
            self::assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS `' . $table . '`[^;]*?FOREIGN KEY \(`entry_id`\) REFERENCES `erp_entries`/s', $migrations,
                "{$table} aponta a conta por entry_id.");
        }
        $invoices = (string)file_get_contents($root . '/source/Services/Erp/Invoices.php');
        self::assertStringContainsString("SET e.status = 'inactive', e.updated_by = :u WHERE it.invoice_id = :id", $invoices, 'A mesma gravação do cancelamento.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/InvoiceEntryCleanup.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('entries')", '$this->registry->isClosed(', 'FOR UPDATE', "i.status = 'cancelled'",
            'AND paid_on IS NULL AND agreement_id IS NULL', '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', 'Audit::record(',
            'rowCount() !== 1'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'INSERT ', 'UPDATE erp_invoices', 'UPDATE erp_bank', 'unit_label', 'description', 'amount_cents', 'SELECT *', 'credential'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar, sem mexer em fatura, boleto ou banco e sem unidade, descrição ou valor: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/InvoiceEntryCleanup.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.invoices.view', \$user)", "Access::can('erp.invoices.manage', \$user)",
            "Access::can(\$entries . '.view', \$user)", "Access::can(\$entries . '.manage', \$user)", 'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/invoices/deactivate-cancelled-entries", "InvoiceEntryCleanup:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/invoice-reconciliation"'), strpos($index, $route), 'Logo depois da conferência.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/invoice-reconciliation:\n"), strpos($openapi, "  /invoices/deactivate-cancelled-entries:\n"));
    }

    private function entryStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_entries WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
