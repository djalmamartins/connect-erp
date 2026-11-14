<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringClosing;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0271: encerramento em lote dos lançamentos fixos que já passaram da última competência. Unit com SQLite (Pdo\Sqlite
 * quando existe; PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas
 * antes do formulário, as contagens dos que ficam de fora (vínculo inativo e erro no formulário) e o contrato de reuso; a
 * regravação pelo cadastro, com auditoria, é conferida no MariaDB (Integration\RecurringClosingTest).
 */
final class RecurringClosingTest extends TestCase
{
    private PDO $pdo;
    private RecurringClosing $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)', 'CREATE TABLE erp_cost_centers (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)', 'CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_recurring_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, cost_center_id INTEGER,
                bank_account_id INTEGER, supplier_id INTEGER, description TEXT, amount_cents INTEGER, day_of_month INTEGER, starts_competence TEXT, ends_competence TEXT,
                notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, status, deleted_at) VALUES (5, 'active', NULL), (6, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_suppliers (id, status, deleted_at) VALUES (9, 'active', NULL), (8, 'inactive', NULL)");
        $past = date('Y-m', strtotime('first day of -3 months'));
        $last = date('Y-m', strtotime('first day of -1 month'));
        $now = date('Y-m');
        $start = date('Y-m', strtotime('first day of -12 months'));
        $insert = $this->pdo->prepare('INSERT INTO erp_recurring_entries (id, condominium_id, nature, chart_account_id, supplier_id, description, amount_cents, day_of_month,
            starts_competence, ends_competence, notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([[1, 1, 'despesa', 5, 9, 'Portaria Fulano Ltda', 10000, 10, $past, 'active', null], [2, 1, 'receita', 5, null, 'Aluguel do salão', 5000, 5, $last, 'active', null],
            [3, 1, 'despesa', 5, null, 'Sem fim', 100, 5, null, 'active', null], [4, 1, 'despesa', 5, null, 'Termina agora', 100, 5, $now, 'active', null],
            [5, 1, 'despesa', 5, 8, 'Fornecedor inativo', 100, 5, $past, 'active', null], [6, 1, 'despesa', 5, null, 'Dia errado', 100, 31, $past, 'active', null],
            [7, 1, 'despesa', 5, null, 'Já inativo', 100, 5, $past, 'inactive', null], [8, 3, 'despesa', 5, null, 'De fora', 100, 5, $past, 'active', null],
            [9, 1, 'despesa', 5, null, 'Excluído', 100, 5, $past, 'active', '2026-01-01 00:00:00'], [10, 1, 'despesa', 6, null, 'Conta excluída', 100, 5, $past, 'active', null]]
            as [$id, $c, $nature, $chart, $supplier, $description, $cents, $day, $end, $status, $deleted]) {
            $insert->execute([$id, $c, $nature, $chart, $supplier, $description, $cents, $day, $start, $end, 'Contrato 123', $status, $deleted]);
        }
        $this->service = new RecurringClosing($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'ends_competence' => '2026-08'];
        self::assertNull(RecurringClosing::problem($row, '2026-11'));
        self::assertStringContainsString('não encontrado', (string)RecurringClosing::problem(null, '2026-11'));
        self::assertStringContainsString('já está inativo', (string)RecurringClosing::problem(['status' => 'inactive'] + $row, '2026-11'));
        self::assertStringContainsString('sem última competência', (string)RecurringClosing::problem(['ends_competence' => null] + $row, '2026-11'));
        self::assertStringContainsString('ainda não passou', (string)RecurringClosing::problem(['ends_competence' => '2026-11'] + $row, '2026-11'), 'O próprio mês vale.');
        self::assertNull(RecurringClosing::problem(['ends_competence' => '2026-10'] + $row, '2026-11'));
        self::assertStringContainsString('está inativo: o formulário não regrava', (string)RecurringClosing::problem($row, '2026-11', true));
        self::assertStringContainsString('formulário não regrava o lançamento fixo (Dia', (string)RecurringClosing::problem($row, '2026-11', false, 'Dia do vencimento: erro'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Contratos encerrados'];
        $future = date('Y-m', strtotime('first day of +1 month'));
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before_competence' => $future] + $ok,
            ['before_competence' => '2026-13'] + $ok, ['before_competence' => '11/2026'] + $ok, ['recurring_ids' => []] + $ok, ['recurring_ids' => ['x']] + $ok,
            ['recurring_ids' => 'abc'] + $ok, ['recurring_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->close($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->close($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[3, 'sem última competência'], [4, 'ainda não passou'], [5, 'está inativo'], [6, 'Dia do vencimento'], [7, 'já está inativo'], [8, 'não encontrado'],
            [9, 'não encontrado'], [10, 'está inativo'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->close($this->user(), ['condominium_id' => 1, 'recurring_ids' => [1, $id], 'reason' => 'Contratos encerrados']);
                self::fail("Lançamento fixo {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_recurring']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Fulano', 'Portaria', 'Contrato 123'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_recurring_entries WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_recurring_entries SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 2)");
        $result = $this->service->close($this->user(), ['condominium_id' => '1', 'reason' => 'Contratos encerrados', 'dry_run' => true]);
        self::assertSame([2, 1, 0, 0, 0], [$result['skipped_inactive_link'], $result['skipped_invalid'], $result['count'], $result['changed'], $result['suppliers_freed']]);
        self::assertSame(['receita' => 0, 'despesa' => 0], $result['monthly_cents']);
        self::assertSame(date('Y-m'), $result['before_competence']);
        $earlier = $this->service->close($this->user(), ['condominium_id' => '1', 'reason' => 'Contratos encerrados', 'dry_run' => true,
            'before_competence' => date('Y-m', strtotime('first day of -3 months'))]);
        self::assertSame([0, 0], [$earlier['skipped_inactive_link'], $earlier['skipped_invalid']], 'Com corte anterior, os de três meses atrás não entram.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/RecurringClosing.php');
        foreach (["\$registry->save(\$user, 'recurring_entries', array_merge(self::formData(\$fields, \$row), ['status' => 'inactive'])", ' FOR UPDATE',
            "Audit::record('close_ended'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('recurring_entries')",
            'VehicleUnitRelease::formError(', 'RecurringEntries::isCompetence('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'description' =>", "['description']", "['notes']", "'supplier_id' => (int)", "'chart_account_id' => (int)"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem descrição na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/RecurringClosing.php');
        foreach (["Access::can('erp.recurring_entries.view', \$user)", "Access::can('erp.recurring_entries.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/recurring-entries/close-ended", "RecurringClosing:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/vacant-units"'), strpos($index, $route), 'Logo depois do relatório das unidades vagas.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
