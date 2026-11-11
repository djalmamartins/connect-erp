<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/** Lista de unidades em ordem natural: bloco e número pelo tamanho (501 antes de 1808). */
final class UnitListOrderIntegrationTest extends TestCase
{
    public function testUnitsAreListedInNaturalOrder(): void
    {
        $condominium = $this->condominium('Alpha');
        $admin = $this->admin($condominium);
        $service = new RegistryService($this->pdo);
        foreach ([['B', '101'], ['', '1808'], ['A', '1001'], ['', '501'], ['A', '201'], ['', '12'], ['A', '1002']] as [$block, $number]) {
            $service->save($admin, 'units', ['condominium_id' => (string)$condominium, 'block' => $block, 'number' => $number, 'type' => 'apartamento']);
        }

        $list = $service->list($admin, 'units', ['condominium_id' => (string)$condominium]);
        $order = array_map(static fn (array $r): string => trim(($r['block'] ?? '') . ' ' . $r['number']), $list['data']);
        self::assertSame(['12', '501', '1808', 'A 201', 'A 1001', 'A 1002', 'B 101'], $order);
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    private function admin(int $condominium): User
    {
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $condominium);
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        return (new User())->findById($id);
    }
}
