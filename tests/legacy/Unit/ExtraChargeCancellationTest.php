<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ExtraChargeCancellation;
use Source\Services\Erp\ExtraChargeOverview;
use Source\Services\Erp\PaymentLocks;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0289: cobranças extras e multas avulsas canceladas em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE e ganha NOW()) para a regra pura, a validação (ids obrigatórios), o escopo, as
 * recusas (uma por regra do cancelamento de uma e as travas do banco do ADR 0194, tudo desfeito), a prévia, a gravação
 * igual à do cancelamento de uma, o relatório da 0288 depois e o contrato. Os tipos vêm de EntryShortcuts::CHARGE_KINDS. O
 * MariaDB confere com cobranças de verdade (Integration\ExtraChargeCancellationTest).
 */
final class ExtraChargeCancellationTest extends TestCase
{
    private PDO $pdo;
    private ExtraChargeCancellation $service;

    protected function setUp(): void
    {
        $now = static fn (): string => '2026-11-14 12:00:00';
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->createFunction('NOW', $now, 0);
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE erp_bank_charges (id INTEGER PRIMARY KEY, entry_id INTEGER, status TEXT)',
            'CREATE TABLE erp_bank_statement_lines (id INTEGER PRIMARY KEY, entry_id INTEGER, status TEXT)',
            'CREATE TABLE erp_extra_charges (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, description TEXT, reason TEXT, competence TEXT, status TEXT,
                created_by INTEGER, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, description TEXT, amount_cents INTEGER, status TEXT, paid_on TEXT,
                due_on TEXT, agreement_id INTEGER NULL, extra_charge_id INTEGER NULL, updated_by INTEGER, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b] = self::kinds();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-10', 'closed')");
        $charge = $this->pdo->prepare("INSERT INTO erp_extra_charges (id, condominium_id, kind, description, reason, competence, status, created_by)
            VALUES (?, ?, ?, 'Pintura do Fulano', 'Pedido do Fulano', ?, ?, 3)");
        foreach ([[1, 1, $a, '2026-11', 'active'], [2, 1, $b, '2026-11', 'active'], [3, 1, $a, '2026-11', 'active'], [4, 1, $a, '2026-11', 'active'],
            [5, 1, $a, '2026-11', 'active'], [6, 1, $a, '2026-11', 'active'], [7, 1, $a, '2026-11', 'cancelled'], [8, 1, $a, '2026-10', 'active'],
            [9, 3, $a, '2026-11', 'active'], [10, 1, $b, '2026-11', 'active']] as $row) {
            $charge->execute($row);
        }
        $entry = $this->pdo->prepare("INSERT INTO erp_entries (id, condominium_id, unit_id, description, amount_cents, status, paid_on, due_on, agreement_id, extra_charge_id)
            VALUES (?, ?, 5, 'Apto 101 do Fulano', ?, ?, ?, '2026-11-30', ?, ?)");
        foreach ([[1, 1, 1000, 'active', null, null, 1], [2, 1, 2000, 'active', null, null, 1], [3, 1, 300, 'active', null, null, 2],
            [4, 1, 400, 'active', '2026-11-05', null, 3], [5, 1, 500, 'inactive', null, 9, 4], [6, 1, 600, 'active', null, null, 5],
            [7, 1, 700, 'active', null, null, 6], [8, 1, 800, 'active', null, null, 10], [9, 1, 900, 'inactive', null, null, 10],
            [10, 1, 100, 'active', null, null, 8], [11, 3, 110, 'active', null, null, 9]] as $row) {
            $entry->execute($row);
        }
        $this->pdo->exec("INSERT INTO erp_bank_charges (entry_id, status) VALUES (6, 'registered'), (1, 'cancelled')");
        $this->pdo->exec("INSERT INTO erp_bank_statement_lines (entry_id, status) VALUES (7, 'matched'), (2, 'ignored')");
        $this->service = new ExtraChargeCancellation($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} o primeiro e o último tipo, vindos de EntryShortcuts::CHARGE_KINDS */
    private static function kinds(): array
    {
        $kinds = array_keys(EntryShortcuts::CHARGE_KINDS);
        self::assertGreaterThanOrEqual(2, count($kinds));
        return [$kinds[0], $kinds[count($kinds) - 1]];
    }

    public function testProblemRule(): void
    {
        $open = ['status' => 'active', 'paid' => false, 'in_agreement' => false, 'active_boleto' => false, 'lock' => null];
        $ok = ['status' => 'active', 'closed' => false, 'entries' => [$open, ['status' => 'inactive'] + $open]];
        self::assertNull(ExtraChargeCancellation::problem($ok), 'Conta já cancelada sem acordo não impede.');
        self::assertNull(ExtraChargeCancellation::problem(['entries' => []] + $ok), 'Cobrança sem conta também cancela.');
        self::assertNotNull(ExtraChargeCancellation::problem(null));
        self::assertNotNull(ExtraChargeCancellation::problem(['status' => 'cancelled'] + $ok));
        self::assertNotNull(ExtraChargeCancellation::problem(['closed' => true] + $ok));
        foreach ([['paid' => true], ['in_agreement' => true], ['active_boleto' => true], ['lock' => PaymentLocks::RECONCILED_REMOVAL]] as $change) {
            self::assertNotNull(ExtraChargeCancellation::problem(['entries' => [$change + $open]] + $ok), json_encode($change));
        }
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Lançadas por engano', 'charge_ids' => [1]];
        foreach ([['condominium_id' => null] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok, ['charge_ids' => null] + $ok,
            ['charge_ids' => []] + $ok, ['charge_ids' => 'x'] + $ok, ['charge_ids' => ['a']] + $ok, ['charge_ids' => [0]] + $ok,
            ['charge_ids' => range(1, ExtraChargeCancellation::MAX_CHARGES + 1)] + $ok] as $input) {
            try {
                $this->service->cancel($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        unset($ok['charge_ids']);
        try {
            $this->service->cancel($this->user(), $ok);
            self::fail('Sem ids passou: cancelar todas de uma vez seria perigoso.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame('active', $this->chargeStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->cancel($this->user(), ['condominium_id' => 3, 'reason' => 'Lançadas por engano', 'charge_ids' => [9]]);
    }

    public function testDryRunThenCancel(): void
    {
        [$a, $b] = self::kinds();
        $input = ['condominium_id' => '1', 'reason' => 'Lançadas por engano', 'charge_ids' => ['10', 1, 2, 1], 'dry_run' => true];
        $preview = $this->service->cancel($this->user(), $input);
        self::assertSame([[1, 2, 10], 3, 5, 4100, 0, true], [array_column($preview['charges'], 'id'), $preview['count'], $preview['entries'], $preview['amount_cents'],
            $preview['changed'], $preview['dry_run']], 'Os valores contam só as contas ativas.');
        self::assertSame(['active', 'active', 'active', 'active'], [$this->chargeStatus(1), $this->chargeStatus(2), $this->chargeStatus(10), $this->entryStatus(1)],
            'A prévia desfaz.');

        $done = $this->service->cancel($this->user(), ['dry_run' => false] + $input);
        self::assertSame([3, 3], [$done['count'], $done['changed']]);
        self::assertSame(array_keys(EntryShortcuts::CHARGE_KINDS), array_column($done['by_kind'], 'kind'));
        $byKind = array_combine(array_column($done['by_kind'], 'kind'), $done['by_kind']);
        self::assertSame([[1, 3000], [2, 1100]], [[$byKind[$a]['charges'], $byKind[$a]['amount_cents']], [$byKind[$b]['charges'], $byKind[$b]['amount_cents']]]);
        self::assertSame($done['count'], array_sum(array_column($done['by_kind'], 'charges')), 'O total é a soma dos tipos.');
        $row = $this->pdo->query('SELECT status, cancelled_by, cancelled_at, cancel_reason FROM erp_extra_charges WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['cancelled', 7, '2026-11-14 12:00:00', 'Lançadas por engano'], [$row['status'], (int)$row['cancelled_by'], $row['cancelled_at'],
            $row['cancel_reason']], 'A mesma gravação do cancelamento de uma.');
        self::assertSame(['inactive', 'inactive', 'inactive', 'inactive'], [$this->entryStatus(1), $this->entryStatus(2), $this->entryStatus(3), $this->entryStatus(8)]);
        self::assertSame(['active', 'active', 'active'], [$this->chargeStatus(3), $this->chargeStatus(5), $this->entryStatus(4)], 'As outras ficam.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE deleted_at IS NOT NULL')->fetchColumn(), 'Nada é apagado.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Pintura', 'Apto', 'engano', 'description', 'reason', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $report = (new ExtraChargeOverview($this->pdo, new CondominiumScope($this->pdo)))->build($this->user(), 1, '2026-11-14');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        self::assertSame([2, 2], [$rows[$a]['cancelled'], $rows[$b]['cancelled']], 'No relatório da 0288, as canceladas aparecem à parte.');
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        $expected = [3 => 'conta paga', 4 => 'acordo', 5 => 'boleto ativo', 6 => PaymentLocks::RECONCILED_REMOVAL, 7 => 'Já está cancelada', 8 => 'competência',
            9 => 'não encontrada', 999 => 'não encontrada'];
        foreach ($expected as $bad => $text) {
            try {
                $this->service->cancel($this->user(), ['condominium_id' => 1, 'reason' => 'Lançadas por engano', 'charge_ids' => [1, $bad]]);
                self::fail("A cobrança {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_charge']);
                self::assertStringContainsString($text, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertSame(['active', 'active'], [$this->chargeStatus(1), $this->entryStatus(1)], 'Tudo ou nada.');
        }
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $single = (string)file_get_contents($root . '/source/Services/Erp/EntryShortcuts.php');
        $service = (string)file_get_contents($root . '/source/Services/Erp/ExtraChargeCancellation.php');
        foreach (["Já está cancelada.", 'Há conta paga. Estorne a baixa antes de cancelar.', 'Há conta renegociada em acordo. Cancele o acordo antes.',
            'Há boleto ativo numa das contas. Cancele o boleto antes.', "status IN ('pending','registered')", "UPDATE erp_entries SET status = 'inactive'",
            "SET status = 'cancelled', cancelled_by", "Audit::record('update', 'erp_extra_charges'"] as $needle) {
            self::assertStringContainsString($needle, $single, 'A regra do cancelamento de uma: ' . $needle);
        }
        self::assertSame(['pending', 'registered'], ExtraChargeCancellation::ACTIVE_BANK_CHARGE, 'O mesmo filtro de boleto ativo do cancelamento de uma.');
        foreach (['$this->scope->assertAccess(', '$this->registry->isClosed(', 'PaymentLocks::find(', 'PaymentLocks::removal(', "EntryShortcuts::reason(", 'FOR UPDATE',
            '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', "Audit::record('update', 'erp_extra_charges'", "AND status = 'active'",
            'rowCount() !== 1', 'Já está cancelada.', 'Há conta paga. Estorne a baixa antes de cancelar.', 'Há conta renegociada em acordo. Cancele o acordo antes.',
            'Há boleto ativo numa das contas. Cancele o boleto antes.'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'description', 'unit_id', 'created_by'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar e sem descrição, unidade ou quem lançou: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ExtraChargeCancellation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.invoices.view', \$user)", "Access::can('erp.invoices.manage', \$user)",
            'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $shortcuts = (string)file_get_contents($root . '/source/Controllers/Api/V1/EntryShortcuts.php');
        self::assertStringContainsString("INVOICES = ['erp.invoices.view', 'erp.invoices.manage']", $shortcuts, 'As mesmas permissões de cancelar uma.');
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/extra-charges/cancel-batch", "ExtraChargeCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/extra-charges"'), strpos($index, $route), 'Logo depois do relatório.');
        self::assertLessThan(strpos($index, '"/extra-charges/{id}"'), strpos($index, $route), 'Antes das rotas /extra-charges/{id}.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/extra-charges:\n"), strpos($openapi, "  /extra-charges/cancel-batch:\n"));
    }

    private function chargeStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_extra_charges WHERE id = {$id}")->fetchColumn();
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
