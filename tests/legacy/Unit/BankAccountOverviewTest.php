<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BankAccountOverview;
use Source\Services\Erp\Registry\RegistryService;

/**
 * ADR 0298: situação das contas bancárias, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2)
 * para a regra pura (linhas por tipo com totais iguais à soma, a conta principal, lançamentos fora das contas ativas à
 * parte, contas paradas, aplicações órfãs e o corte), a montagem sem apelido, banco, agência, número nem valor (período
 * nos limites, lançamento cancelado, excluído ou sem pagamento, transferência cancelada, conta excluída e outro condomínio
 * de fora), a validação, o escopo e o contrato. Os tipos vêm do cadastro; nada de quantidade nem lista fixa. O MariaDB
 * confere com contas de verdade (Integration\BankAccountOverviewTest).
 */
final class BankAccountOverviewTest extends TestCase
{
    private PDO $pdo;
    private BankAccountOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, bank_code TEXT, branch TEXT, account_number TEXT, kind TEXT,
                parent_bank_account_id INTEGER NULL, is_main INTEGER, opening_balance_cents INTEGER, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, bank_account_id INTEGER NULL, description TEXT, amount_cents INTEGER, paid_on TEXT NULL,
                status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, description TEXT, status TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $account = $this->pdo->prepare("INSERT INTO erp_bank_accounts (id, condominium_id, name, bank_code, branch, account_number, kind, parent_bank_account_id, is_main,
            opening_balance_cents, status, deleted_at) VALUES (?, ?, 'Conta Secreta', '341', '1234', '98765-4', ?, ?, ?, 777777, ?, ?)");
        foreach ([[1, 1, 'corrente', null, 1, 'active', null], [2, 1, 'corrente', null, 0, 'active', null], [3, 1, 'poupanca', null, 0, 'active', null],
            [4, 1, 'aplicacao', 1, 0, 'active', null], [5, 1, 'aplicacao', 7, 0, 'active', null], [6, 1, 'aplicacao', null, 0, 'active', null],
            [7, 1, 'corrente', null, 1, 'inactive', null], [8, 1, 'corrente', null, 0, 'active', '2026-10-01 10:00:00'], [9, 3, 'corrente', null, 1, 'active', null],
            [10, 1, 'aplicacao', 9, 0, 'active', null]] as $row) {
            $account->execute($row);
        }
        $entry = $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, bank_account_id, description, amount_cents, paid_on, status, deleted_at)
            VALUES (?, ?, 'Pagamento Reservado', 55555, ?, ?, ?)");
        foreach ([[1, 1, '2026-11-01', 'active', null], [1, 1, '2026-08-17', 'active', null], [1, 3, '2026-08-16', 'active', null],
            [1, 1, '2026-10-01', 'inactive', null], [1, 1, '2026-10-02', 'active', '2026-10-03 10:00:00'], [1, null, '2026-10-03', 'active', null],
            [1, 7, '2026-10-04', 'active', null], [1, 1, null, 'active', null], [3, 9, '2026-10-05', 'active', null]] as $row) {
            $entry->execute($row);
        }
        $transfer = $this->pdo->prepare("INSERT INTO erp_bank_transfers (condominium_id, from_bank_account_id, to_bank_account_id, amount_cents, transferred_on, description, status)
            VALUES (1, ?, ?, 4444, ?, 'Transferência Reservada', ?)");
        foreach ([[2, 1, '2026-09-01', 'active'], [1, 4, '2026-10-01', 'active'], [3, 1, '2026-09-02', 'cancelled'], [3, 1, '2026-08-01', 'active']] as $row) {
            $transfer->execute($row);
        }
        $this->service = new BankAccountOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummaryFromTheCondominium(): void
    {
        $kinds = BankAccountOverview::kinds();
        foreach (['corrente', 'poupanca', 'aplicacao'] as $kind) {
            self::assertArrayHasKey($kind, $kinds, 'O tipo usado pela regra da aplicação existe no cadastro.');
        }
        $report = $this->service->build($this->user(), '1', '90', '2026-11-14');
        self::assertSame([1, 90, '2026-08-17', '2026-11-14'], [$report['condominium_id'], $report['days'], $report['from'], $report['to']]);
        self::assertSame(array_keys($kinds), array_column($report['rows'], 'kind'), 'Todos os tipos, sempre.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        $pick = static fn (array $r): array => [$r['accounts'], $r['inactive'], $r['main'], $r['idle'], $r['orphan'], $r['paid_entries'], $r['transfer_legs']];
        self::assertSame([2, 1, 1, 0, 0, 2, 3], $pick($rows['corrente']),
            'A principal inativa não conta; sem o lançamento cancelado, o excluído, o não pago e a transferência cancelada.');
        self::assertSame([1, 0, 0, 1, 0, 0, 0], $pick($rows['poupanca']), 'O lançamento e a transferência de antes do período não contam.');
        self::assertSame([4, 0, 0, 3, 3, 0, 1], $pick($rows['aplicacao']), 'Sem conta corrente, com a corrente inativa ou de outro condomínio: órfã.');
        foreach (array_keys($report['totals']) as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        self::assertNull($report['main_issue'], 'Uma conta principal ativa.');
        self::assertSame(['paid_entries' => 2], $report['elsewhere'], 'Sem conta ou em conta inativa, à parte.');
        self::assertSame(4, $report['all_paid_entries']);
        self::assertSame($report['all_paid_entries'], $report['totals']['paid_entries'] + $report['elsewhere']['paid_entries']);
        self::assertSame([[3, 5, 6, 10], false], [$report['idle_ids'], $report['idle_truncated']]);
        self::assertSame([[5, 6, 10], false], [$report['orphan_ids'], $report['orphan_truncated']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Conta Secreta', '341', '98765', '777777', '55555', '4444', 'Reservad', 'name', 'bank_code', 'branch', 'account_number', 'balance'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testMainIssueCutAndEmpty(): void
    {
        $kind = array_key_first(BankAccountOverview::kinds());
        $none = BankAccountOverview::summarize([['id' => 1, 'kind' => $kind, 'status' => 'active', 'is_main' => false, 'orphan' => false]], [], []);
        self::assertSame('none', $none['main_issue']);
        $two = BankAccountOverview::summarize([['id' => 1, 'kind' => $kind, 'status' => 'active', 'is_main' => true, 'orphan' => false],
            ['id' => 2, 'kind' => $kind, 'status' => 'active', 'is_main' => true, 'orphan' => false]], [], []);
        self::assertSame('multiple', $two['main_issue']);
        $many = [];
        for ($i = 1; $i <= BankAccountOverview::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'kind' => $kind, 'status' => 'active', 'is_main' => $i === 1, 'orphan' => true];
        }
        $report = BankAccountOverview::summarize($many, [], []);
        self::assertSame([BankAccountOverview::MAX_IDS, BankAccountOverview::MAX_IDS, true, true], [count($report['idle_ids']), count($report['orphan_ids']),
            $report['idle_truncated'], $report['orphan_truncated']]);
        self::assertSame(BankAccountOverview::MAX_IDS + 1, $report['totals']['accounts'], 'A contagem não é cortada.');
        $empty = BankAccountOverview::summarize([], ['' => 3], []);
        self::assertSame([count(BankAccountOverview::kinds()), 0, 3, 'none'], [count($empty['rows']), $empty['totals']['accounts'], $empty['all_paid_entries'],
            $empty['main_issue']], 'Sem conta cadastrada, tudo fica à parte.');
    }

    public function testValidationAndScope(): void
    {
        foreach ([[null, null], ['', null], [0, null], ['abc', null], [1, '0'], [1, 'x'], [1, (string)(BankAccountOverview::MAX_DAYS + 1)], [[], null]] as [$c, $d]) {
            try {
                $this->service->build($this->user(), $c, $d, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode([$c, $d]));
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
        self::assertSame(['erp_bank_accounts', 'erp_entries'], [RegistryService::definition('bank_accounts')['table'], RegistryService::definition('entries')['table']]);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0534_entry_shortcuts.sql');
        self::assertMatchesRegularExpression("/CREATE TABLE IF NOT EXISTS `erp_bank_transfers`.*?`status` enum\\('active','cancelled'\\)/s", $sql, 'Transferência válida é active.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/BankAccountOverview.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('bank_accounts')", "RegistryService::definition('entries')", 'SELECT NOW()',
            "deleted_at IS NULL AND status = 'active'", 'paid_on BETWEEN ? AND ?', "condominium_id = ? AND status = 'active'", 'p.condominium_id <> b.condominium_id'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'b.name', 'bank_code', 'branch', 'account_number', 'amount_cents', 'opening_balance', 'wallet'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem apelido, banco, agência, número ou valor: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/BankAccountOverview.php');
        foreach (["Access::can('erp.access', \$user)", "['bank_accounts', 'entries']", "RegistryService::definition(\$slug)['permission'] . '.view'",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/bank-accounts", "BankAccountOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/invoice-types/deactivate-unused"'), strpos($index, $route), 'Logo depois do lote de tipos de faturas.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /invoice-types/deactivate-unused:\n"), strpos($openapi, "  /reports/bank-accounts:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
