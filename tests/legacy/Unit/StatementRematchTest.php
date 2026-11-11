<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\StatementRematch;

/**
 * ADR 0209: nova conferência do extrato pendente. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2); o PDO de
 * teste tira o FOR UPDATE. Conta 10 e 11 do condomínio 1; conta 20 do condomínio 2.
 */
final class StatementRematchTest extends TestCase
{
    private PDO $pdo;
    private StatementRematch $service;

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
        $pdo = $this->pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, bank_account_id INTEGER, nature TEXT, amount_cents INTEGER, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, from_bank_account_id INTEGER, to_bank_account_id INTEGER, transferred_on TEXT, amount_cents INTEGER, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_bank_statement_lines (id INTEGER PRIMARY KEY, condominium_id INTEGER, bank_account_id INTEGER, posted_on TEXT, amount_cents INTEGER, memo TEXT,
                status TEXT, entry_id INTEGER, transfer_id INTEGER, note TEXT, resolved_by INTEGER, resolved_at TEXT)'] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name) VALUES (10, 1, 'Conta movimento'), (11, 1, 'Poupança'), (20, 2, 'Conta Brisa')");
        $pdo->exec("INSERT INTO erp_entries (id, bank_account_id, nature, amount_cents, paid_on, status, deleted_at) VALUES
            (1, 10, 'receita', 15000, '2026-11-06', 'active', NULL),
            (2, 10, 'despesa', 8000, '2026-11-07', 'active', NULL), (3, 10, 'despesa', 8000, '2026-11-08', 'active', NULL),
            (4, 10, 'receita', 5000, '2026-11-10', 'active', NULL),
            (5, 10, 'receita', 7000, '2026-11-02', 'active', NULL),
            (6, 10, 'receita', 9000, '2026-11-02', 'inactive', NULL), (8, 10, 'receita', 9000, '2026-11-02', 'active', '2026-11-03'),
            (9, 10, 'receita', 3000, NULL, 'active', NULL)");
        $pdo->exec("INSERT INTO erp_bank_transfers (id, from_bank_account_id, to_bank_account_id, transferred_on, amount_cents) VALUES (1, 10, 11, '2026-11-03', 20000)");
        $pdo->exec("INSERT INTO erp_bank_statement_lines (id, condominium_id, bank_account_id, posted_on, amount_cents, memo, status, entry_id) VALUES
            (1, 1, 10, '2026-11-05', 15000, 'PIX RECEBIDO JOAO DA SILVA', 'unmatched', NULL),
            (2, 1, 10, '2026-11-07', -8000, NULL, 'unmatched', NULL),
            (3, 1, 10, '2026-11-01', 5000, NULL, 'unmatched', NULL),
            (4, 1, 10, '2026-11-03', -20000, NULL, 'unmatched', NULL),
            (5, 1, 10, '2026-11-02', 12000, NULL, 'ignored', NULL),
            (6, 1, 10, '2026-11-02', 7000, NULL, 'unmatched', NULL),
            (7, 1, 10, '2026-11-02', 7000, NULL, 'matched', 5),
            (8, 1, 11, '2026-11-03', 20000, NULL, 'unmatched', NULL),
            (9, 1, 10, '2026-11-02', 9000, NULL, 'unmatched', NULL),
            (10, 1, 10, '2026-11-02', 3000, NULL, 'unmatched', NULL),
            (11, 2, 20, '2026-11-05', 15000, NULL, 'unmatched', NULL)");
        $this->service = new StatementRematch($pdo, new CondominiumScope($pdo));
    }

    public function testRematchUsesTheImportRuleAllOrPreview(): void
    {
        $preview = $this->service->rematch($this->user(), ['condominium_id' => 1, 'dry_run' => true]);
        self::assertSame([true, 0, 8, 3, 5], [$preview['dry_run'], $preview['changed'], $preview['pending_before'], $preview['matched'], $preview['still_pending']]);
        self::assertSame([[4, null, 1, 'out'], [1, 1, null, 'in'], [8, null, 1, 'in']],
            array_map(static fn (array $l): array => [$l['id'], $l['entry_id'], $l['transfer_id'], $l['direction']], $preview['lines']),
            'Linha 2: dois candidatos; 3: 9 dias; 6: lançamento já conciliado; 9: cancelado ou excluído; 10: não pago.');
        self::assertSame([['Conta movimento', 7, 2], ['Poupança', 1, 1]], array_map(static fn (array $a): array => [$a['name'], $a['pending_before'], $a['matched']], $preview['accounts']));
        self::assertSame(8, $this->pending(), 'dry_run não grava.');
        self::assertStringNotContainsString('JOAO', (string)json_encode($preview), 'O histórico do banco não sai.');

        $done = $this->service->rematch($this->user(), ['condominium_id' => 1]);
        self::assertSame(3, $done['changed']);
        self::assertSame([['matched', 1, null, StatementRematch::NOTE, 7], ['ignored', null, null, null, null]],
            [$this->line(1), $this->line(5)], 'Ignorada não muda.');
        self::assertSame(['matched', null, 1], array_slice($this->line(8), 0, 3));
        self::assertSame(5, $this->pending());
        self::assertSame('unmatched', $this->pdo->query('SELECT status FROM erp_bank_statement_lines WHERE id = 11')->fetchColumn(), 'Outro condomínio não muda.');
        self::assertSame(0, $this->service->rematch($this->user(), ['condominium_id' => 1])['matched'], 'Repetir não acha mais nada.');
    }

    public function testBankFilterValidationScopeAndContract(): void
    {
        $only = $this->service->rematch($this->user(), ['condominium_id' => 1, 'bank_account_id' => '11', 'dry_run' => true]);
        self::assertSame([[11], [8]], [array_column($only['accounts'], 'bank_account_id'), array_column($only['lines'], 'id')]);
        foreach ([[], ['condominium_id' => 'x'], ['condominium_id' => 1, 'bank_account_id' => 'x'], ['condominium_id' => 1, 'bank_account_id' => [10]],
            ['condominium_id' => 1, 'bank_account_id' => 0]] as $input) {
            try {
                $this->service->rematch($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->rematch($this->user(), ['condominium_id' => 1, 'bank_account_id' => 20]);
            self::fail('Conta de outro condomínio passou.');
        } catch (RegistryNotFound) {
            self::assertSame(8, $this->pending());
        }
        try {
            $this->service->rematch($this->user(), ['condominium_id' => 3]);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/StatementRematch.php');
        foreach (['ImportRules::match(', ' FOR UPDATE', "WHERE id = ? AND status = 'unmatched'", "Audit::record('update', 'erp_bank_statement_lines'", '$this->pdo->rollBack()'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/StatementRematch.php');
        foreach (["Access::can('erp.imports.manage', \$user)", "Access::can('erp.entries.manage', \$user)", "Access::can('erp.imports.view', \$user)",
            'catch (CondominiumAccessDenied | RegistryNotFound)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/bank-statement-lines/rematch", "StatementRematch:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function pending(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_bank_statement_lines WHERE status = 'unmatched' AND condominium_id = 1")->fetchColumn();
    }

    /** @return list<mixed> status, entry_id, transfer_id, note, resolved_by */
    private function line(int $id): array
    {
        $r = $this->pdo->query("SELECT status, entry_id, transfer_id, note, resolved_by FROM erp_bank_statement_lines WHERE id = {$id}")->fetch(PDO::FETCH_NUM);
        return array_map(static fn ($v) => is_numeric($v) && !is_string($v) ? (int)$v : (is_string($v) && ctype_digit($v) ? (int)$v : $v), $r);
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
