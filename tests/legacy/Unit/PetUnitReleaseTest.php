<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PetUnitRelease;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VehicleUnitRelease;

/**
 * ADR 0269: inativação em lote dos animais de unidades sem morador ativo. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, o erro do formulário sem valor do cadastro, a validação,
 * o escopo, as recusas antes do formulário, as contagens dos que ficam de fora e o contrato de reuso; a regravação pelo
 * cadastro, com auditoria, é conferida no MariaDB (Integration\PetUnitReleaseTest).
 */
final class PetUnitReleaseTest extends TestCase
{
    private PDO $pdo;
    private PetUnitRelease $service;

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
            'CREATE TABLE erp_pets (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, species TEXT, size TEXT, breed TEXT,
                tutor_name TEXT, vaccinated INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
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
        $this->pdo->exec("INSERT INTO erp_pets (id, condominium_id, unit_id, unit_label, name, species, size, breed, tutor_name, vaccinated, status, deleted_at) VALUES
            (1, 1, 40, '101', 'Rex', 'cao', 'grande', 'Labrador', 'Fulano Tutor', 1, 'active', NULL), (2, 1, 42, '102', 'Mimi', 'gato', NULL, NULL, NULL, 0, 'active', NULL),
            (3, 1, 41, '103', 'Bob', 'cao', NULL, NULL, NULL, 0, 'active', NULL), (4, 1, NULL, '104', 'Lulu', 'cao', NULL, NULL, NULL, 0, 'active', NULL),
            (5, 1, 43, '105', 'Tom', 'gato', NULL, NULL, NULL, 0, 'active', NULL), (6, 1, 44, '106', 'Drako', 'dragao', NULL, NULL, NULL, 0, 'active', NULL),
            (7, 1, 40, '101', 'Velho', 'cao', NULL, NULL, NULL, 0, 'inactive', NULL), (8, 3, 50, '1', 'Fora', 'cao', NULL, NULL, NULL, 0, 'active', NULL),
            (9, 1, 40, '101', 'Excluído', 'cao', NULL, NULL, NULL, 0, 'active', '2026-01-01 00:00:00')");
        $this->service = new PetUnitRelease($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemAndFormErrorPureRules(): void
    {
        $row = ['status' => 'active', 'unit_id' => 40];
        self::assertNull(PetUnitRelease::problem($row, false));
        self::assertStringContainsString('não encontrado', (string)PetUnitRelease::problem(null, false));
        self::assertStringContainsString('já está inativo', (string)PetUnitRelease::problem(['status' => 'inactive'] + $row, false));
        self::assertStringContainsString('não tem unidade vinculada', (string)PetUnitRelease::problem(['unit_id' => null] + $row, false));
        self::assertStringContainsString('ainda tem morador ativo', (string)PetUnitRelease::problem($row, true));
        self::assertStringContainsString('unidade do animal está inativa', (string)PetUnitRelease::problem($row, false, false));
        self::assertStringContainsString('formulário não regrava o animal (Espécie', (string)PetUnitRelease::problem($row, false, true, 'Espécie: inválida'));

        $definition = RegistryService::definition('pets');
        $data = ['condominium_id' => '1', 'unit_id' => '40', 'unit_label' => '101', 'name' => 'Rex', 'species' => 'cao', 'size' => 'grande', 'breed' => 'Labrador',
            'tutor_name' => 'Fulano Tutor', 'vaccinated' => '1', 'notes' => null];
        self::assertNull(VehicleUnitRelease::formError($definition, $data));
        $error = (string)VehicleUnitRelease::formError($definition, ['species' => 'dragao'] + $data);
        self::assertStringStartsWith('Espécie: ', $error);
        self::assertStringNotContainsString('dragao', $error, 'Só o rótulo e a mensagem, sem o valor.');
        self::assertStringStartsWith('Nome: ', (string)VehicleUnitRelease::formError($definition, ['name' => null] + $data));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Família se mudou'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['reason' => str_repeat('a', 256)] + $ok, ['pet_ids' => []] + $ok,
            ['pet_ids' => ['x']] + $ok, ['pet_ids' => 'abc'] + $ok, ['pet_ids' => range(1, 501)] + $ok] as $input) {
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
        foreach ([[3, 'ainda tem morador ativo'], [4, 'não tem unidade vinculada'], [5, 'inativa'], [6, 'Espécie'], [7, 'já está inativo'], [8, 'não encontrado'],
            [9, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->release($this->user(), ['condominium_id' => 1, 'pet_ids' => [1, $id], 'reason' => 'Família se mudou']);
                self::fail("Animal {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_pet']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Rex', 'Drako', 'dragao', 'Fulano', 'Labrador'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_pets WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        $this->pdo->exec("UPDATE erp_pets SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 2)");
        $result = $this->service->release($this->user(), ['condominium_id' => '1', 'reason' => 'Família se mudou', 'dry_run' => true]);
        self::assertSame([1, 1, 1, 0, 0], [$result['skipped_unlinked'], $result['skipped_inactive_unit'], $result['skipped_invalid'], $result['count'], $result['changed']]);
        self::assertSame(array_fill_keys(array_keys(RegistryService::definition('pets')['fields']['species']['options']), 0), $result['by_species'],
            'Todas as espécies do cadastro, zeradas.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/PetUnitRelease.php');
        foreach (["\$registry->save(\$user, 'pets', array_merge(self::formData(\$fields, \$row), ['status' => 'inactive'])", ' FOR UPDATE',
            "Audit::record('deactivate_unattached'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('pets')",
            'VehicleUnitRelease::formError(', "(ends_on IS NULL OR ends_on >= ?)"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'name' =>", "['name']", "['breed']", "['tutor_name']", "['vaccinated']",
            "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem dado do animal na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/PetUnitRelease.php');
        foreach (["Access::can('erp.pets.view', \$user)", "Access::can('erp.pets.manage', \$user)", "Access::can('erp.residents.view', \$user)",
            'catch (CondominiumAccessDenied)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/pets/deactivate-unattached", "PetUnitRelease:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/resident-portal"'), strpos($index, $route), 'Logo depois do relatório da adesão ao portal.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
