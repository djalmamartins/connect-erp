<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Support\FirstAdmin;

/** ADR 0380: primeiro administrador da instalação limpa, uma vez só. */
final class FirstAdminTest extends TestCase
{
    private function role(string $slug): int
    {
        $this->pdo->prepare("INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 10)")->execute([$slug, $slug]);
        return (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS system_protected_users');
        parent::tearDown();
    }

    public function testCreatesProtectedSuperAdminWithAllPermissionsOnce(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS system_protected_users (user_id INT UNSIGNED NOT NULL PRIMARY KEY, reason VARCHAR(255) NOT NULL)');
        $roleId = $this->role('super_admin');
        $this->registerMigrationPermissions();
        $admin = FirstAdmin::create($this->pdo, ' Admin@Connect.Example ', 'Ana', 'Souza', self::validCpf());

        $user = $this->pdo->query('SELECT email, level, status, password FROM users WHERE id = ' . $admin['id'])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['admin@connect.example', 10, 'confirmed'], [$user['email'], (int)$user['level'], $user['status']]);
        self::assertTrue(password_verify($admin['password'], $user['password']));
        self::assertSame(20, strlen($admin['password']));
        self::assertMatchesRegularExpression('/[A-Z]/', $admin['password']);
        self::assertMatchesRegularExpression('/[a-z]/', $admin['password']);
        self::assertMatchesRegularExpression('/\d/', $admin['password']);
        self::assertMatchesRegularExpression('/[@#%&*+=?]/', $admin['password']);
        self::assertSame($roleId, (int)$this->pdo->query('SELECT role_id FROM access_user_roles WHERE user_id = ' . $admin['id'])->fetchColumn());
        $all = (int)$this->pdo->query('SELECT COUNT(*) FROM access_permissions')->fetchColumn();
        self::assertGreaterThan(50, $all);
        self::assertSame($all, (int)$this->pdo->query('SELECT COUNT(*) FROM access_role_permissions WHERE role_id = ' . $roleId)->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM system_protected_users WHERE user_id = ' . $admin['id'])->fetchColumn());

        try {
            FirstAdmin::create($this->pdo, 'outro@connect.example', 'Bia', 'Lima', self::validCpf());
            self::fail('segundo administrador criado');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Já existe administrador', $error->getMessage());
        }
    }

    public function testRefusesBadDataAndMissingRole(): void
    {
        self::assertSame('E-mail inválido.', FirstAdmin::problem('x', 'Ana', 'Souza', self::validCpf()));
        self::assertSame('Informe nome e sobrenome.', FirstAdmin::problem('a@b.co', 'Ana', ' ', self::validCpf()));
        self::assertSame('CPF inválido.', FirstAdmin::problem('a@b.co', 'Ana', 'Souza', '11111111111'));
        $this->expectExceptionMessage('Papel super_admin não cadastrado');
        FirstAdmin::create($this->pdo, 'a@connect.example', 'Ana', 'Souza', self::validCpf());
    }

    public function testDeveloperAlsoCountsAsExistingAdmin(): void
    {
        $this->role('super_admin');
        $dev = $this->role('developer');
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$this->createUser(), $dev]);
        $this->expectException(\DomainException::class);
        FirstAdmin::create($this->pdo, 'a@connect.example', 'Ana', 'Souza', self::validCpf());
    }
}
