<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VehicleUnitRelease;

/**
 * ADR 0267: inativação em lote dos veículos de unidades sem morador ativo. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, o erro do formulário sem valor do cadastro, a validação,
 * o escopo, as recusas antes do formulário, as contagens dos que ficam de fora e o contrato de reuso; a regravação pelo
 * cadastro, com auditoria, é conferida no MariaDB (Integration\VehicleUnitReleaseTest).
 */
final class VehicleUnitReleaseTest extends TestCase
{
    private PDO $pdo;
    private VehicleUnitRelease $service;

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
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, name TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_vehicles (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, plate TEXT, kind TEXT, model TEXT, color TEXT,
                parking_spot TEXT, owner_name TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $past = date('Y-m-d', strtotime('-30 days'));
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, status, deleted_at) VALUES (40, 1, 'active', NULL), (41, 1, 'active', NULL), (42, 1, 'active', NULL),
            (43, 1, 'inactive', NULL), (44, 1, 'active', NULL)");
        // 40: sem morador (saiu); 41: com morador ativo; 42: só morador com fim passado; 43: inativa; 44: só morador inativo.
        $this->pdo->exec("INSERT INTO erp_residents (condominium_id, unit_id, name, ends_on, status, deleted_at) VALUES (1, 41, 'Morador', NULL, 'active', NULL),
            (1, 42, 'Saiu', '{$past}', 'active', NULL), (1, 44, 'Inativo', NULL, 'inactive', NULL), (1, 40, 'Excluído', NULL, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_vehicles (id, condominium_id, unit_id, unit_label, plate, kind, model, owner_name, status, deleted_at) VALUES
            (1, 1, 40, '101', 'QWE9R87', 'carro', 'Sedã Prata', 'Fulano Dono', 'active', NULL), (2, 1, 42, '102', 'XYZ1234', 'moto', NULL, NULL, 'active', NULL),
            (3, 1, 41, '103', 'AAA1A11', 'carro', NULL, NULL, 'active', NULL), (4, 1, NULL, '104', 'BBB2B22', 'carro', NULL, NULL, 'active', NULL),
            (5, 1, 43, '105', 'CCC3C33', 'carro', NULL, NULL, 'active', NULL), (6, 1, 44, '106', 'INVALID', 'carro', NULL, NULL, 'active', NULL),
            (7, 1, 40, '101', 'DDD4D44', 'carro', NULL, NULL, 'inactive', NULL), (8, 3, 50, '1', 'EEE5E55', 'carro', NULL, NULL, 'active', NULL),
            (9, 1, 40, '101', 'FFF6F66', 'carro', NULL, NULL, 'active', '2026-01-01 00:00:00')");
        $this->service = new VehicleUnitRelease($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemAndFormErrorPureRules(): void
    {
        $row = ['status' => 'active', 'unit_id' => 40];
        self::assertNull(VehicleUnitRelease::problem($row, false));
        self::assertStringContainsString('não encontrado', (string)VehicleUnitRelease::problem(null, false));
        self::assertStringContainsString('já está inativo', (string)VehicleUnitRelease::problem(['status' => 'inactive'] + $row, false));
        self::assertStringContainsString('não tem unidade vinculada', (string)VehicleUnitRelease::problem(['unit_id' => null] + $row, false));
        self::assertStringContainsString('ainda tem morador ativo', (string)VehicleUnitRelease::problem($row, true));
        self::assertStringContainsString('unidade do veículo está inativa', (string)VehicleUnitRelease::problem($row, false, false));
        self::assertStringContainsString('formulário não regrava o veículo (Placa', (string)VehicleUnitRelease::problem($row, false, true, 'Placa: inválida'));

        $definition = RegistryService::definition('vehicles');
        $data = ['condominium_id' => '1', 'unit_id' => '40', 'unit_label' => '101', 'plate' => 'QWE9R87', 'kind' => 'carro', 'model' => null, 'color' => null,
            'parking_spot' => null, 'owner_name' => 'Fulano Dono', 'notes' => null];
        self::assertNull(VehicleUnitRelease::formError($definition, $data));
        $plate = (string)VehicleUnitRelease::formError($definition, ['plate' => 'INVALID'] + $data);
        self::assertStringStartsWith('Placa: ', $plate);
        self::assertStringNotContainsString('INVALID', $plate, 'Só o rótulo e a mensagem, sem o valor.');
        self::assertStringStartsWith('Tipo: ', (string)VehicleUnitRelease::formError($definition, ['kind' => 'nave'] + $data));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Família se mudou'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['reason' => str_repeat('a', 256)] + $ok, ['vehicle_ids' => []] + $ok,
            ['vehicle_ids' => ['x']] + $ok, ['vehicle_ids' => 'abc'] + $ok, ['vehicle_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->release($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->release($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[3, 'ainda tem morador ativo'], [4, 'não tem unidade vinculada'], [5, 'inativa'], [6, 'Placa'], [7, 'já está inativo'], [8, 'não encontrado'],
            [9, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->release($this->user(), ['condominium_id' => 1, 'vehicle_ids' => [1, $id], 'reason' => 'Família se mudou']);
                self::fail("Veículo {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_vehicle']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['QWE9R87', 'INVALID', 'Fulano', 'Sedã'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_vehicles WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_vehicles SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 2)");
        $result = $this->service->release($this->user(), ['condominium_id' => '1', 'reason' => 'Família se mudou', 'dry_run' => true]);
        self::assertSame([1, 1, 1, 0, 0], [$result['skipped_unlinked'], $result['skipped_inactive_unit'], $result['skipped_invalid'], $result['count'], $result['changed']]);
        self::assertSame(['carro' => 0, 'moto' => 0, 'utilitario' => 0, 'outro' => 0], $result['by_kind']);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VehicleUnitRelease.php');
        foreach (["\$registry->save(\$user, 'vehicles', array_merge(self::formData(\$fields, \$row), ['status' => 'inactive'])", ' FOR UPDATE',
            "Audit::record('deactivate_unattached'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('vehicles')",
            'RegistryValidator::validate(', "(ends_on IS NULL OR ends_on >= ?)"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'plate' =>", "['plate']", "['owner_name']", "['model']", "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem dado do veículo na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VehicleUnitRelease.php');
        foreach (["Access::can('erp.vehicles.view', \$user)", "Access::can('erp.vehicles.manage', \$user)", "Access::can('erp.residents.view', \$user)",
            'catch (CondominiumAccessDenied)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/vehicles/deactivate-unattached", "VehicleUnitRelease:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/visitor-units"'), strpos($index, $route), 'Logo depois do relatório dos visitantes por unidade.');
        self::assertLessThan(strpos($index, '"/vehicles/{id}"') ?: PHP_INT_MAX, strpos($index, $route), 'Antes do laço dos cadastros.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
