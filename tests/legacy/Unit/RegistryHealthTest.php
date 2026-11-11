<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LabSampleData;
use Source\Services\Erp\RegistryHealth;

/** ADR 0185: qualidade do cadastro do condomínio. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2). */
final class RegistryHealthTest extends TestCase
{
    private PDO $pdo;
    private RegistryHealth $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, ideal_fraction REAL, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT, document TEXT,
                status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, status TEXT DEFAULT 'active', deleted_at TEXT)",
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $this->service = new RegistryHealth($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testFindsEveryProblemWithoutNamesOrDocuments(): void
    {
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, ideal_fraction, status, deleted_at) VALUES
            (11, 1, '101', 0.5, 'active', NULL), (12, 1, '102', NULL, 'active', NULL), (13, 1, '103', 0.25, 'active', NULL),
            (14, 1, ' 103 ', 0.25, 'active', NULL), (15, 1, '105', NULL, 'inactive', NULL), (16, 1, '106', NULL, 'active', '2026-01-01')");
        $ok = LabSampleData::cpf('111444777');
        $this->pdo->exec("INSERT INTO erp_residents (id, condominium_id, unit_id, unit_label, relationship, name, document, status, deleted_at) VALUES
            (1, 1, 11, '101', 'owner', 'Ana Dona', '{$ok}', 'active', NULL),
            (2, 1, 13, '103', 'owner', 'Bruno Dono', '123.456.789-00', 'active', NULL),
            (3, 1, 13, '103', 'tenant', 'Carla Inquilina', '', 'active', NULL),
            (4, 1, 14, ' 103 ', 'financial', 'Davi Financeiro', NULL, 'active', NULL),
            (5, 1, 12, '102', 'owner', 'Ex-dono', '{$ok}', 'inactive', NULL),
            (6, 1, 12, '102', 'resident', 'Morador sem documento', NULL, 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, unit_id, nature, status, deleted_at) VALUES
            (1, 12, 'receita', 'active', NULL), (1, 14, 'despesa', 'active', NULL), (1, 14, 'receita', 'inactive', NULL)");

        $r = $this->service->check($this->user(), 1);
        $checks = array_column($r['checks'], null, 'code');
        self::assertSame(['charged_without_owner', 'duplicate_unit_label', 'fraction_missing', 'document_invalid', 'units_without_owner', 'document_missing'], array_keys($checks));
        self::assertSame(['102'], array_column($checks['charged_without_owner']['items'], 'unit_label'), 'A 102 é cobrada e o dono está inativo; a 104 só tem despesa ou conta cancelada.');
        self::assertSame([13, 14], array_column($checks['duplicate_unit_label']['items'], 'unit_id'), 'Rótulo igual ignorando espaço e maiúscula.');
        self::assertSame(['102'], array_column($checks['fraction_missing']['items'], 'unit_label'), 'Unidade inativa ou excluída não conta.');
        self::assertSame([2], array_column($checks['document_invalid']['items'], 'resident_id'));
        self::assertSame(['102', ' 103 '], array_column($checks['units_without_owner']['items'], 'unit_label'));
        self::assertSame([4], array_column($checks['document_missing']['items'], 'resident_id'), 'Inquilino e morador sem documento não entram; o financeiro entra.');
        self::assertSame([false, 3, 3], [$r['ok'], $r['errors'], $r['warnings']]);
        self::assertSame(['units' => 4, 'units_with_owner' => 2, 'people' => 4], $r['summary']);
        $json = json_encode($r, JSON_UNESCAPED_UNICODE);
        foreach (['Ana Dona', 'Bruno Dono', '123.456.789-00', $ok] as $secret) {
            self::assertStringNotContainsString($secret, (string)$json, 'Nem nome nem documento na resposta.');
        }
    }

    public function testFractionsAndCleanCondominium(): void
    {
        $ok = LabSampleData::cpf('222555888');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, ideal_fraction) VALUES (21, 2, 'A', 0.6), (22, 2, 'B', 0.4)");
        $this->pdo->exec("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document) VALUES
            (2, 21, 'A', 'owner', 'X', '{$ok}'), (2, 22, 'B', 'owner', 'Y', '{$ok}')");
        self::assertSame([true, [], 0], [($clean = $this->service->check($this->user(), 2))['ok'], $clean['checks'], $clean['warnings']], 'Cadastro certo: nada a apontar.');
        $this->pdo->exec('UPDATE erp_units SET ideal_fraction = 0.5 WHERE id = 21');
        $sum = array_column($this->service->check($this->user(), 2)['checks'], null, 'code')['fraction_sum'];
        self::assertSame('error', $sum['level']);
        self::assertStringContainsString('0,9', $sum['message']);
        $this->pdo->exec('UPDATE erp_units SET ideal_fraction = NULL');
        self::assertSame('info', array_column($this->service->check($this->user(), 2)['checks'], null, 'code')['fractions_not_set']['level'], 'Sem fração nenhuma: rateio igual.');
        self::assertSame([], RegistryHealth::evaluate([['id' => 1, 'label' => 'A', 'ideal_fraction' => 0.50005], ['id' => 2, 'label' => 'B', 'ideal_fraction' => 0.5]],
            [1, 2], [], [], 0.0001)['checks'], 'Dentro da tolerância.');

        try {
            $this->service->check($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service->check($this->user(), null);
    }

    public function testControllerChecksPermissions(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/RegistryHealth.php');
        foreach (["Access::can('erp.units.view', \$user)", "Access::can('erp.residents.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
