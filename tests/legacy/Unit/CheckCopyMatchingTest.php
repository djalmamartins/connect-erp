<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CheckCopyMatching;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0249: vínculo em lote das cópias de cheques aos lançamentos pagos. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura do par (conta, valor, prazo, dúvida), a validação, o escopo,
 * as recusas antes do formulário e o contrato de reuso; a regravação pelo cadastro e a auditoria são conferidas no MariaDB
 * (Integration\CheckCopyMatchingTest).
 */
final class CheckCopyMatchingTest extends TestCase
{
    private PDO $pdo;
    private CheckCopyMatching $service;

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
            'CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, amount_cents INTEGER, paid_on TEXT, bank_account_id INTEGER,
                description TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_check_copies (id INTEGER PRIMARY KEY, condominium_id INTEGER, bank_account_id INTEGER, check_number INTEGER, issued_on TEXT, payee TEXT,
                amount_cents INTEGER, document_id INTEGER, entry_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, status, deleted_at) VALUES (21, 1, 'Movimento', 'active', NULL), (22, 1, 'Antiga', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (51, 1, NULL), (52, 1, '2026-10-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, nature, amount_cents, paid_on, bank_account_id, description, status, deleted_at) VALUES
            (101, 1, 'despesa', 100000, '2026-10-05', 21, 'Serviço', 'active', NULL),
            (103, 1, 'despesa', 80000, '2026-10-05', 21, 'Serviço', 'active', NULL),
            (104, 1, 'despesa', 80000, '2026-10-06', 21, 'Serviço', 'active', NULL),
            (107, 1, 'despesa', 60000, '2026-10-02', 21, 'Já ligado', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_check_copies (id, condominium_id, bank_account_id, check_number, issued_on, payee, amount_cents, document_id, entry_id, status,
            deleted_at) VALUES
            (1, 1, 21, 2001, '2026-10-01', 'Fulano Favorecido', 100000, 51, NULL, 'active', NULL),
            (2, 1, 21, 2002, '2026-10-01', 'Beltrano', 100000, 51, NULL, 'inactive', NULL),
            (3, 1, 21, 2003, '2026-10-01', 'Ciclano', 60000, 51, 107, 'active', NULL),
            (4, 1, 22, 2004, '2026-10-01', 'Deltrano', 100000, 51, NULL, 'active', NULL),
            (5, 1, 21, 2005, '2026-10-01', 'Ermano', 100000, 52, NULL, 'active', NULL),
            (6, 3, 21, 2006, '2026-10-01', 'Outro', 100000, 51, NULL, 'active', NULL),
            (7, 1, 21, 2007, '2026-10-01', 'Excluído', 100000, 51, NULL, 'active', '2026-10-02 00:00:00'),
            (8, 1, 21, 2008, '2026-10-01', 'Sem par', 55500, 51, NULL, 'active', NULL),
            (11, 1, 21, 2011, '2026-10-03', 'Dois pares', 80000, 51, NULL, 'active', NULL)");
        $this->service = new CheckCopyMatching($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testCandidatesAndPairPureRule(): void
    {
        $check = ['bank_account_id' => 21, 'amount_cents' => 100000, 'issued_on' => '2026-10-01'];
        $e = static fn (int $id, string $paid, int $amount = 100000, int $bank = 21): array => ['id' => $id, 'bank_account_id' => $bank, 'amount_cents' => $amount,
            'paid_on' => $paid];
        self::assertSame([1, 2], CheckCopyMatching::candidates($check, [$e(1, '2026-10-01'), $e(2, '2026-10-31'), $e(3, '2026-11-01'), $e(4, '2026-09-30'),
            $e(5, '2026-10-05', 100001), $e(6, '2026-10-05', 100000, 22)], 30), 'Do dia do cheque até 30 dias, mesma conta e mesmo valor.');
        self::assertSame([1], CheckCopyMatching::candidates($check, [$e(1, '2026-10-01'), $e(2, '2026-10-02')], 0), 'Prazo 0: só no dia.');

        $checks = [10 => $check, 11 => ['issued_on' => '2026-10-02'] + $check, 12 => ['amount_cents' => 80000] + $check, 13 => ['amount_cents' => 55500] + $check,
            14 => ['amount_cents' => 70000] + $check];
        $paired = CheckCopyMatching::pair($checks, [$e(1, '2026-10-05'), $e(2, '2026-10-05', 80000), $e(3, '2026-10-06', 80000), $e(4, '2026-10-04', 70000)], 30);
        self::assertSame(['pairs' => [14 => 4], 'no_match' => [13], 'ambiguous' => [10, 11, 12]], $paired,
            'Dois cheques no mesmo lançamento e um cheque com dois lançamentos ficam de fora.');
        self::assertStringContainsString('não encontrado', (string)CheckCopyMatching::problem(null));
        self::assertStringContainsString('cancelado', (string)CheckCopyMatching::problem(['status' => 'inactive', 'entry_id' => null]));
        self::assertStringContainsString('já está ligado', (string)CheckCopyMatching::problem(['status' => 'active', 'entry_id' => 5]));
        self::assertStringContainsString('conta bancária', (string)CheckCopyMatching::problem(['status' => 'active', 'entry_id' => null], false));
        self::assertNull(CheckCopyMatching::problem(['status' => 'active', 'entry_id' => null]));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Conferência do talão'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['window_days' => -1] + $ok, ['window_days' => 121] + $ok,
            ['window_days' => 'x'] + $ok, ['check_copy_ids' => []] + $ok, ['check_copy_ids' => ['x']] + $ok, ['check_copy_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->link($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->link($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'cancelado'], [3, 'já está ligado'], [4, 'conta bancária'], [5, 'imagem'], [6, 'não encontrado'], [7, 'não encontrado'], [99, 'não encontrado'],
            [8, 'Nenhum lançamento'], [11, 'Mais de um']] as [$id, $message]) {
            try {
                $this->service->link($this->user(), ['condominium_id' => 1, 'check_copy_ids' => [1, $id], 'reason' => 'Conferência do talão']);
                self::fail("Cheque {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_check']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertNull($this->pdo->query('SELECT entry_id FROM erp_check_copies WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/CheckCopyMatching.php');
        foreach (["\$registry->save(\$user, 'check_copies', array_merge(\$data, ['entry_id' => (string)\$entry])", ' FOR UPDATE', "Audit::record('link_entry'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "number_format((int)\$value / 100, 2, ',', '')",
            'NOT EXISTS (SELECT 1 FROM erp_check_copies k WHERE k.entry_id = e.id AND k.deleted_at IS NULL)'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_check_copies', 'UPDATE erp_entries', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'payee' =>", "['payee']",
            'e.description', "'description' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, não toca o lançamento, sem aviso e sem favorecido: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CheckCopyMatching.php');
        foreach (["Access::can('erp.check_copies.view', \$user)", "Access::can('erp.check_copies.manage', \$user)", "Access::can('erp.entries.view', \$user)",
            'catch (CondominiumAccessDenied)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/check-copies/link-entries", "CheckCopyMatching:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
