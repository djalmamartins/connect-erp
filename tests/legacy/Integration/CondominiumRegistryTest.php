<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumRegistry;
use Source\Services\CondominiumScope;

/** AP-004: cadastro/edição com permissão, escopo, CNPJ único e auditoria. */
final class CondominiumRegistryTest extends TestCase
{
    private const INPUT = [
        'name' => 'Residencial Aurora', 'legal_name' => 'Condomínio Residencial Aurora', 'document' => '11.222.333/0001-81',
        'status' => 'active', 'zip_code' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000',
        'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP', 'fee_due_day' => '10',
    ];

    public function testManagerWithoutGlobalAccessCreatesAndReceivesScope(): void
    {
        $user = $this->userWith(['erp.access', 'erp.condominiums.manage']);
        $result = (new CondominiumRegistry($this->pdo))->create($user, self::INPUT);

        self::assertTrue($result['ok'], json_encode($result['errors']));
        $row = $this->pdo->query("SELECT * FROM condominiums WHERE id = {$result['id']}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('11222333000181', $row['document']);
        self::assertSame('10', (string)$row['fee_due_day']);
        self::assertSame((string)$user->id, (string)$row['created_by']);
        self::assertTrue((new CondominiumScope($this->pdo))->canAccess($user, (int)$result['id']), 'Quem cadastra passa a ter o vínculo.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'condominiums' AND action = 'create'")->fetchColumn());
    }

    public function testDuplicateCnpjIsRejected(): void
    {
        $user = $this->userWith(['erp.access', 'erp.condominiums.manage']);
        $registry = new CondominiumRegistry($this->pdo);
        self::assertTrue($registry->create($user, self::INPUT)['ok']);

        $second = $registry->create($user, ['name' => 'Outro nome'] + self::INPUT);
        self::assertFalse($second['ok']);
        self::assertArrayHasKey('document', $second['errors']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM condominiums')->fetchColumn());
    }

    public function testUpdateChangesDataAuditsAndKeepsOwnCnpj(): void
    {
        $user = $this->userWith(['erp.access', 'erp.condominiums.manage']);
        $registry = new CondominiumRegistry($this->pdo);
        $id = (int)$registry->create($user, self::INPUT)['id'];

        $result = $registry->update($user, $id, ['name' => 'Residencial Aurora II', 'status' => 'inactive'] + self::INPUT);
        self::assertTrue($result['ok'], json_encode($result['errors']));
        self::assertSame('Residencial Aurora II', $this->pdo->query("SELECT name FROM condominiums WHERE id = {$id}")->fetchColumn());
        self::assertNotNull($registry->find($user, $id), 'Condomínio inativo continua editável dentro do escopo.');

        $audit = (string)$this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'condominiums' AND action = 'update'")->fetchColumn();
        self::assertStringContainsString('"name"', $audit);
    }

    public function testUpdateOutsideScopeIsDenied(): void
    {
        $owner = $this->userWith(['erp.access', 'erp.condominiums.manage']);
        $other = $this->userWith(['erp.access', 'erp.condominiums.manage'], 'manager2');
        $registry = new CondominiumRegistry($this->pdo);
        $id = (int)$registry->create($owner, self::INPUT)['id'];

        self::assertNull($registry->find($other, $id));
        $this->expectException(CondominiumAccessDenied::class);
        $registry->update($other, $id, self::INPUT);
    }

    public function testViewOnlyUserCannotWrite(): void
    {
        $viewer = $this->userWith(['erp.access', 'erp.condominiums.view'], 'viewer');
        $this->expectException(\DomainException::class);
        (new CondominiumRegistry($this->pdo))->create($viewer, self::INPUT);
    }

    /** Cria usuário com papel próprio e as permissões indicadas (sem acesso global). */
    private function userWith(array $permissions, string $roleSlug = 'manager'): User
    {
        $userId = $this->createUser(['level' => 2]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$roleSlug, $roleSlug]);
        $roleId = (int)$this->pdo->lastInsertId();
        foreach ($permissions as $slug) {
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$slug, $slug]);
            $permissionId = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote($slug))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, $permissionId]);
        }
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        return (new User())->findById($userId);
    }
}
