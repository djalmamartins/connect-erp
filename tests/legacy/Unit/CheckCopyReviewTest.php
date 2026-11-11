<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CheckCopyReview;

/**
 * ADR 0214: conferência das cópias de cheques, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para as regras puras, a validação, o escopo e a montagem; o cadastro real pelo formulário é conferido no MariaDB
 * (Integration\CheckCopyReviewTest).
 */
final class CheckCopyReviewTest extends TestCase
{
    private PDO $pdo;
    private CheckCopyReview $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, amount_cents INTEGER, paid_on TEXT, bank_account_id INTEGER,
                status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_check_copies (id INTEGER PRIMARY KEY, condominium_id INTEGER, bank_account_id INTEGER, check_number INTEGER, issued_on TEXT,
                payee TEXT, amount_cents INTEGER, entry_id INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo = $pdo;
        $this->service = new CheckCopyReview($pdo, new CondominiumScope($pdo));
    }

    public function testGapsPureRule(): void
    {
        self::assertSame(['first' => null, 'last' => null, 'missing_count' => 0, 'missing' => []], CheckCopyReview::gaps([]));
        self::assertSame(['first' => 10, 'last' => 12, 'missing_count' => 0, 'missing' => []], CheckCopyReview::gaps([12, 10, 11, 11]));
        self::assertSame(['first' => 1, 'last' => 9, 'missing_count' => 4, 'missing' => [2, 3, 6, 8]], CheckCopyReview::gaps([1, 4, 5, 7, 9]));
        $wide = CheckCopyReview::gaps([1, 1000], 3);
        self::assertSame([998, [2, 3, 4]], [$wide['missing_count'], $wide['missing']], 'Lista limitada, contagem completa.');
    }

    public function testIssuesPureRule(): void
    {
        $check = ['status' => 'active', 'amount_cents' => 35000, 'bank_account_id' => 5, 'entry_id' => 9];
        $entry = ['status' => 'active', 'nature' => 'despesa', 'amount_cents' => 35000, 'paid_on' => '2026-11-09', 'bank_account_id' => 5];
        self::assertSame([], CheckCopyReview::issues($check, $entry));
        self::assertSame(['no_entry'], CheckCopyReview::issues(['entry_id' => null] + $check, null));
        self::assertSame(['entry_missing'], CheckCopyReview::issues($check, null));
        self::assertSame(['entry_cancelled'], CheckCopyReview::issues($check, ['status' => 'inactive'] + $entry));
        self::assertSame(['not_expense', 'amount_mismatch'], CheckCopyReview::issues($check, ['nature' => 'receita', 'amount_cents' => 1] + $entry));
        self::assertSame(['other_account'], CheckCopyReview::issues($check, ['bank_account_id' => 6] + $entry));
        self::assertSame(['entry_unpaid'], CheckCopyReview::issues($check, ['paid_on' => null, 'bank_account_id' => null] + $entry));
        self::assertSame(['cancelled_but_paid'], CheckCopyReview::issues(['status' => 'inactive'] + $check, $entry));
        self::assertSame([], CheckCopyReview::issues(['status' => 'inactive'] + $check, ['paid_on' => null] + $entry));
        self::assertSame([], CheckCopyReview::issues(['status' => 'inactive', 'entry_id' => null] + $check, null));
    }

    public function testBuildWithoutPayee(): void
    {
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name) VALUES (5, 1, 'Movimento'), (6, 1, 'Reserva'), (8, 3, 'Outra')");
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, nature, amount_cents, paid_on, bank_account_id, status) VALUES
            (20, 1, 'despesa', 35000, '2026-11-09', 5, 'active'), (21, 1, 'despesa', 99000, '2026-11-09', 5, 'active'), (22, 3, 'despesa', 100, NULL, 8, 'active')");
        $this->pdo->exec("INSERT INTO erp_check_copies (id, condominium_id, bank_account_id, check_number, issued_on, payee, amount_cents, entry_id, status) VALUES
            (1, 1, 5, 1001, '2026-11-01', 'Fulano de Tal', 35000, 20, 'active'),
            (2, 1, 5, 1004, '2026-11-02', 'Fulano de Tal', 50000, 21, 'active'),
            (3, 1, 5, 1005, '2026-11-03', 'Beltrano', 100, 22, 'active'),
            (4, 1, 6, 1, '2026-11-04', 'Ciclano', 700, NULL, 'inactive'),
            (5, 3, 8, 50, '2026-11-04', 'Outro', 100, 22, 'active')");
        $report = $this->service->build($this->user(), '1');
        self::assertSame([1, 4, 2], [$report['condominium_id'], $report['checks'], $report['missing_numbers']]);
        self::assertSame([['Movimento', 3, 0, 85100, 1001, 1005, [1002, 1003]], ['Reserva', 1, 1, 0, 1, 1, []]], array_map(static fn (array $a): array => [$a['bank_account_name'],
            $a['checks'], $a['cancelled'], $a['issued_cents'], $a['first'], $a['last'], $a['missing']], $report['accounts']));
        self::assertSame([[2, ['amount_mismatch']], [3, ['entry_missing']]], array_map(static fn (array $i): array => [$i['check_copy_id'], $i['issues']], $report['items']));
        self::assertSame(1, $report['summary']['amount_mismatch']);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Beltrano', 'Ciclano', 'payee', 'Outra'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem favorecido nem dado de outro condomínio: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, '', 'x', 0, [1]] as $input) {
            try {
                $this->service->build($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/CheckCopyReview.php');
        foreach (['k.payee', 'INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'NotificationOutbox'] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Somente leitura e sem favorecido: ' . $needle);
        }
        self::assertStringContainsString('e.condominium_id = k.condominium_id', $service);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CheckCopyReview.php');
        foreach (["Access::can('erp.check_copies.view', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/check-copy-review", "CheckCopyReview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
