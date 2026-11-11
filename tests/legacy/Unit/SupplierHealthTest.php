<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\SupplierHealth;

/**
 * ADR 0200: conferência do cadastro de fornecedores. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). O
 * usuário 7 acessa só o condomínio 1; o 8 é developer (acesso a todos).
 */
final class SupplierHealthTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private SupplierHealth $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, legal_name TEXT, trade_name TEXT, document TEXT, category TEXT DEFAULT 'outros', email TEXT, phone TEXT,
                bank_account TEXT, pix_key TEXT, status TEXT DEFAULT 'active', created_at TEXT DEFAULT '2020-01-01 00:00:00', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, nature TEXT, amount_cents INTEGER, due_on TEXT, paid_on TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $pdo->exec("INSERT INTO access_roles (id, slug) VALUES (1, 'developer')");
        $pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (8, 1)');
        $pdo->exec("INSERT INTO erp_suppliers (id, legal_name, trade_name, document, email, phone, bank_account, pix_key, status, created_at, deleted_at) VALUES
            (1, 'Limpa Tudo Serviços Ltda', 'LimpaTudo', '11222333000181', 'contato@example.test', '11999990000', NULL, 'pix-limpa@example.test', 'active', '2020-01-01', NULL),
            (2, 'LIMPA TUDO SERVIÇOS - ME', NULL, '52998224725', NULL, NULL, NULL, NULL, 'active', '2020-01-01', NULL),
            (3, 'Elevadores Sobe S/A', NULL, '11222333000182', NULL, NULL, '12345-6', NULL, 'inactive', '2020-01-01', NULL),
            (4, 'Jardim Bonito', NULL, '11144477735', NULL, NULL, '  ', '', 'active', '2020-01-01', NULL),
            (5, 'Portaria Antiga', NULL, '11444777000161', NULL, NULL, NULL, NULL, 'active', '2020-01-01', NULL),
            (6, 'Recém Cadastrado', NULL, '11144477735', NULL, NULL, NULL, NULL, 'active', '2026-10-01', NULL),
            (7, 'Excluído', NULL, '000', NULL, NULL, NULL, NULL, 'active', '2020-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO erp_entries (condominium_id, supplier_id, nature, amount_cents, due_on, paid_on, status, deleted_at) VALUES
            (1, 3, 'despesa', 30000, '2026-11-20', NULL, 'active', NULL), (3, 3, 'despesa', 99999, '2026-11-20', NULL, 'active', NULL),
            (1, 3, 'despesa', 77777, '2026-11-20', NULL, 'inactive', NULL), (1, 3, 'despesa', 66666, '2026-11-20', '2026-11-01', 'active', NULL),
            (1, 4, 'despesa', 12000, '2026-08-10', '2026-08-10', 'active', NULL), (1, 2, 'despesa', 5000, '2025-01-10', '2025-01-10', 'active', NULL),
            (1, 1, 'despesa', 8000, '2026-09-10', '2026-09-10', 'active', NULL), (1, 5, 'despesa', 4000, '2023-05-10', '2023-05-10', 'active', NULL)");
        $this->service = new SupplierHealth($pdo, new CondominiumScope($pdo));
    }

    public function testPartialScopeUser(): void
    {
        $r = $this->service->build($this->user(7), self::TODAY);
        self::assertSame([false, 1, 6, 5], [$r['global_scope'], $r['condominiums'], $r['suppliers'], $r['active_suppliers']], 'Excluído fora.');
        self::assertSame(['invalid_document' => 1, 'similar_names' => 1, 'inactive_in_use' => 1, 'no_payment_data' => 1, 'dormant' => null], $r['summary']);
        self::assertSame([3], array_column($r['invalid_document']['items'], 'supplier_id'));
        self::assertSame([['limpa tudo servicos', [1, 2]]], array_map(static fn (array $g): array => [$g['normalized_name'], array_column($g['suppliers'], 'supplier_id')],
            $r['similar_names']['items']), 'Acento, caixa, pontuação e LTDA/ME não separam o mesmo fornecedor.');
        self::assertSame([[3, 1, 30000]], array_map(static fn (array $i): array => [$i['supplier_id'], $i['open_entries'], $i['open_cents']], $r['inactive_in_use']['items']),
            'Só os condomínios do usuário; cancelada e paga não contam.');
        self::assertSame([4], array_column($r['no_payment_data']['items'], 'supplier_id'), 'Pago nos últimos 12 meses sem Pix nem conta; o 2 foi pago há mais tempo.');
        self::assertNull($r['dormant']);
        self::assertSame(['partial_scope'], array_column($r['warnings'], 'code'));
        $json = (string)json_encode($r);
        foreach (['contato@example.test', '11999990000', 'pix-limpa', '12345-6', '11222333000181'] as $secret) {
            self::assertStringNotContainsString($secret, $json, 'E-mail, telefone, Pix, conta e documento inteiro não saem.');
        }
        self::assertSame('************81', $r['similar_names']['items'][0]['suppliers'][0]['document_masked']);
    }

    public function testGlobalUserAndPureRule(): void
    {
        $r = $this->service->build($this->user(8), self::TODAY);
        self::assertSame([true, 2], [$r['global_scope'], $r['condominiums']]);
        self::assertSame([[3, 2, 129999]], array_map(static fn (array $i): array => [$i['supplier_id'], $i['open_entries'], $i['open_cents']], $r['inactive_in_use']['items']));
        self::assertSame([5], array_column($r['dormant']['items'], 'supplier_id'), 'Sem lançamento em 24 meses; o recém-cadastrado não conta.');
        self::assertSame([], $r['warnings']);

        self::assertSame('limpa tudo servicos', SupplierHealth::normalizeName('  Limpa-Tudo   Serviços LTDA. '));
        self::assertSame('elevadores sobe', SupplierHealth::normalizeName('Elevadores Sobe S/A'));
        self::assertSame('elevadores sobe', SupplierHealth::normalizeName('ELEVADORES SOBE S.A.'));
        self::assertSame('acme comercio', SupplierHealth::normalizeName('Acme Comércio EIRELI EPP'), 'Comércio fica; só os sufixos do fim saem.');
        self::assertSame('me lanches', SupplierHealth::normalizeName('ME Lanches'), 'Sufixo só no fim.');
    }

    public function testControllerAndRoute(): void
    {
        $root = dirname(__DIR__, 3);
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/SupplierHealth.php');
        foreach (["Access::can('erp.suppliers.view', \$user)", "Access::can('erp.entries.view', \$user)", 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/supplier-health", "SupplierHealth:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }
}
