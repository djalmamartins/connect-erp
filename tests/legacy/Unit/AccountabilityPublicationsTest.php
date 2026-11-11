<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Accountability;
use Source\Services\Erp\AccountabilityPublications;

/** ADR 0179: publicação congelada da prestação de contas mensal. Unit com SQLite (Pdo\Sqlite quando existe; PDO no 8.2). */
final class AccountabilityPublicationsTest extends TestCase
{
    private PDO $pdo;
    private AccountabilityPublications $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, chart_account_id INTEGER, bank_account_id INTEGER, description TEXT,
                amount_cents INTEGER, competence TEXT, due_on TEXT, paid_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, opening_balance_cents INTEGER, opened_on TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_transfers (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, from_bank_account_id INTEGER, to_bank_account_id INTEGER, amount_cents INTEGER,
                transferred_on TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT, revenue_cents INTEGER, expense_cents INTEGER,
                open_revenue_cents INTEGER, open_expense_cents INTEGER, summary_json TEXT, closed_by INTEGER, closed_at TEXT, reopened_by INTEGER, reopened_at TEXT, reopen_reason TEXT)',
            'CREATE TABLE erp_chart_account_openings (id INTEGER PRIMARY KEY, condominium_id INTEGER, chart_account_id INTEGER, opened_on TEXT, amount_cents INTEGER, notes TEXT,
                updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (condominium_id, chart_account_id))',
            "CREATE TABLE erp_patrimonial_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_date TEXT, description TEXT, amount_cents INTEGER, debit_account_id INTEGER,
                credit_account_id INTEGER, bank_account_id INTEGER, linked_entry_id INTEGER, import_id INTEGER, status TEXT DEFAULT 'active', reversal_of_id INTEGER, created_by INTEGER)",
            "CREATE TABLE erp_accountability_publications (id INTEGER PRIMARY KEY, condominium_id INTEGER NOT NULL, competence TEXT NOT NULL, version INTEGER NOT NULL,
                sha256 TEXT NOT NULL, payload_json TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'current', notes TEXT, published_by INTEGER, published_at TEXT NOT NULL,
                withdrawn_by INTEGER, withdrawn_at TEXT, withdraw_reason TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (condominium_id, competence, version))",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind) VALUES
            (1, NULL, '1', 'Receitas', 'receita', 'sintetica'), (2, NULL, '1.01', 'Taxa condominial', 'receita', 'analitica'),
            (3, NULL, '2', 'Despesas', 'despesa', 'sintetica'), (4, NULL, '2.01', 'Energia', 'despesa', 'analitica')");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (id, condominium_id, name, opening_balance_cents, opened_on) VALUES (10, 1, 'Movimento', 50000, '2026-01-01')");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on) VALUES
            (1, 'receita', 2, 10, 'Taxa out', 100000, '2026-10', '2026-10-10', '2026-10-10'), (1, 'despesa', 4, 10, 'Energia out', 30000, '2026-10', '2026-10-20', '2026-10-20'),
            (3, 'receita', 2, NULL, 'Fora', 5000, '2026-10', '2026-10-10', '2026-10-10')");
        $scope = new CondominiumScope($this->pdo);
        $noDelinquency = static fn (User $u, int $c, string $asOf): array => ['as_of' => $asOf, 'units' => [], 'totals' => []];
        $this->service = new AccountabilityPublications($this->pdo, $scope, new Accountability($this->pdo, $scope, $noDelinquency));
    }

    public function testPublishOnlyClosedMonthsAndFreezesTheVersion(): void
    {
        try {
            $this->service->publish($this->user(), 1, '2026-10');
            self::fail('Publicou o mês aberto.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Feche o mês', $error->getMessage());
        }
        $this->close('2026-10');
        $first = $this->service->publish($this->user(), 1, '2026-10', 'Enviada ao conselho');
        self::assertSame([true, 1, 'current', 'Enviada ao conselho'], [$first['created'], $first['version'], $first['status'], $first['notes']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['sha256']);

        $again = $this->service->publish($this->user(), 1, '2026-10');
        self::assertSame([false, $first['id'], 1], [$again['created'], $again['id'], $again['version']], 'Mesmo conteúdo: devolve a versão atual.');

        $frozen = $this->service->find($this->user(), $first['id']);
        self::assertTrue($frozen['intact']);
        self::assertSame(100000, $frozen['payload']['summary']['revenue_cents']);

        // O mês é reaberto, uma despesa entra e o mês fecha de novo: nova versão; a antiga continua igual.
        $this->pdo->exec("UPDATE erp_month_closings SET status = 'reopened' WHERE competence = '2026-10'");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (1, 'despesa', 4, 10, 'Energia complementar', 5000, '2026-10', '2026-10-25', '2026-10-25')");
        $this->pdo->exec("UPDATE erp_month_closings SET status = 'closed' WHERE competence = '2026-10'");
        $second = $this->service->publish($this->user(), 1, '2026-10');
        self::assertSame([true, 2], [$second['created'], $second['version']]);
        $list = $this->service->list($this->user(), 1, '2026-10');
        self::assertSame([[2, 'current'], [1, 'superseded']], array_map(static fn (array $r): array => [$r['version'], $r['status']], $list['rows']));
        self::assertArrayNotHasKey('payload', $list['rows'][0], 'A lista não traz o conteúdo.');
        self::assertSame(30000, $this->service->find($this->user(), $first['id'])['payload']['summary']['expense_cents'], 'A versão 1 continua como foi entregue.');
        self::assertSame(35000, $this->service->find($this->user(), $second['id'])['payload']['summary']['expense_cents']);

        // Alteração direta no banco é percebida: o sha256 não bate.
        $this->pdo->exec("UPDATE erp_accountability_publications SET payload_json = REPLACE(payload_json, '30000', '1') WHERE id = {$first['id']}");
        self::assertFalse($this->service->find($this->user(), $first['id'])['intact']);
    }

    public function testWithdrawScopeAndValidation(): void
    {
        $this->close('2026-10');
        $pub = $this->service->publish($this->user(), 1, '2026-10');
        foreach (['', 'ok', str_repeat('x', 256)] as $bad) {
            try {
                $this->service->withdraw($this->user(), $pub['id'], $bad);
                self::fail('Aceitou o motivo ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $out = $this->service->withdraw($this->user(), $pub['id'], 'Valor da energia estava errado');
        self::assertSame(['withdrawn', 'Valor da energia estava errado', 7], [$out['status'], $out['withdraw_reason'], $out['withdrawn_by']]);
        try {
            $this->service->withdraw($this->user(), $pub['id'], 'De novo');
            self::fail('Retirou duas vezes.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        self::assertSame([true, 2], [($p = $this->service->publish($this->user(), 1, '2026-10'))['created'], $p['version']], 'Depois de retirar, publica a versão seguinte.');

        // Outro condomínio: 404 em tudo, sem dizer se existe.
        $this->pdo->exec("INSERT INTO erp_accountability_publications (id, condominium_id, competence, version, sha256, payload_json, published_at)
            VALUES (900, 3, '2026-10', 1, '" . str_repeat('0', 64) . "', '{}', '2026-11-01 10:00:00')");
        foreach ([fn () => $this->service->find($this->user(), 900), fn () => $this->service->find($this->user(), 999),
            fn () => $this->service->withdraw($this->user(), 900, 'Motivo válido'), fn () => $this->service->list($this->user(), 3),
            fn () => $this->service->publish($this->user(), 3, '2026-10')] as $call) {
            try {
                $call();
                self::fail('Fora do escopo passou.');
            } catch (CondominiumAccessDenied) {
                self::assertTrue(true);
            }
        }
        foreach ([fn () => $this->service->publish($this->user(), null, '2026-10'), fn () => $this->service->publish($this->user(), 1, '2026-13'),
            fn () => $this->service->list($this->user(), null)] as $call) {
            try {
                $call();
                self::fail('Entrada inválida passou.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(AccountabilityPublications::canonical(['b' => 1, 'a' => [2, 1]]), AccountabilityPublications::canonical(['a' => [2, 1], 'b' => 1]),
            'Chaves em qualquer ordem dão o mesmo texto; listas mantêm a ordem.');

        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/AccountabilityPublications.php');
        foreach (["Access::can('erp.closing.manage', \$user)", "Access::can('erp.closing.view', \$user)", '(new ReportExportLimit())->assertAllowed($user)',
            "Audit::record('export'", 'catch (CondominiumAccessDenied)', 'X-Content-Type-Options: nosniff'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $migration = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261111_0712_accountability_publications.sql');
        self::assertStringContainsString('UNIQUE KEY `uq_erp_accountability_publications_version` (`condominium_id`, `competence`, `version`)', $migration);
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261111_0712_accountability_publications.down.sql');
    }

    private function close(string $competence): void
    {
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status, closed_by, closed_at) VALUES (1, '{$competence}', 'closed', 7, '2026-11-02 10:00:00')");
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
