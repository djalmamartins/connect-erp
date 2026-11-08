<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\Registry as RegistryApi;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/** Cadastros do ERP: escopo por condomínio, regras, auditoria e API (ADR 0014). */
final class RegistryServiceTest extends TestCase
{
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
    }

    public function testCreateListAndFindStayInsideUserScope(): void
    {
        $manager = $this->user([$this->alpha]);
        $other = $this->user([$this->beta]);
        $service = new RegistryService($this->pdo);

        $created = $service->save($manager, 'employees', $this->employee($this->alpha));
        self::assertSame('Ana Souza', $created['name']);
        self::assertSame('Alpha', $created['condominium_name']);
        self::assertSame(215050, $created['salary_cents']);

        self::assertSame(1, $service->list($manager, 'employees')['total']);
        self::assertSame(0, $service->list($other, 'employees')['total'], 'Outro condomínio não vê o funcionário.');
        $this->assertNotFound(fn () => $service->find($other, 'employees', $created['id']));
        $this->assertNotFound(fn () => $service->save($other, 'employees', $this->employee($this->beta), $created['id']));
        $this->assertNotFound(fn () => $service->delete($other, 'employees', $created['id']));
    }

    public function testCannotCreateInCondominiumOutsideScope(): void
    {
        $user = $this->user([$this->alpha]);
        try {
            (new RegistryService($this->pdo))->save($user, 'vehicles', ['condominium_id' => $this->beta, 'unit_label' => 'A-101', 'plate' => 'ABC1D23', 'kind' => 'carro']);
            self::fail('Gravou em condomínio fora do escopo.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('condominium_id', $exception->errors);
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_vehicles')->fetchColumn());
    }

    public function testUniquenessSoftDeleteAndAudit(): void
    {
        $user = $this->user([$this->alpha]);
        $service = new RegistryService($this->pdo);
        $vehicle = ['condominium_id' => $this->alpha, 'unit_label' => 'A-101', 'plate' => 'abc-1d23', 'kind' => 'carro'];
        $first = $service->save($user, 'vehicles', $vehicle);
        try {
            $service->save($user, 'vehicles', $vehicle);
            self::fail('Placa repetida no mesmo condomínio foi aceita.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('plate', $exception->errors);
        }

        $updated = $service->save($user, 'vehicles', $vehicle + ['model' => 'Fiat Argo'], $first['id']);
        self::assertSame('Fiat Argo', $updated['model'], 'Alterar o próprio registro não conflita com a unicidade.');

        $service->delete($user, 'vehicles', $first['id']);
        self::assertSame(0, $service->list($user, 'vehicles')['total']);
        self::assertNotNull($this->pdo->query('SELECT deleted_at FROM erp_vehicles WHERE id = ' . (int)$first['id'])->fetchColumn(), 'Exclusão é lógica.');
        $again = $service->save($user, 'vehicles', $vehicle);
        self::assertNotSame($first['id'], $again['id'], 'Depois de excluída, a placa pode ser cadastrada de novo.');

        $actions = $this->pdo->query("SELECT action FROM system_audit_logs WHERE entity = 'erp_vehicles' AND entity_id = " . (int)$first['id'] . ' ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['create', 'update', 'delete'], $actions);
        $_SESSION['authUser'] = $user->id;
        $history = $service->history($user, 'vehicles', $again['id']);
        self::assertSame('create', $history[0]['action']);
    }

    public function testAuditMasksPersonalData(): void
    {
        $user = $this->user([$this->alpha]);
        (new RegistryService($this->pdo))->save($user, 'residents', [
            'condominium_id' => $this->alpha, 'unit_label' => 'B-202', 'relationship' => 'owner', 'name' => 'Carlos Lima',
            'document' => '529.982.247-25', 'email' => 'carlos@exemplo.com', 'phone' => '19998765432',
        ]);
        $context = (string)$this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_residents'")->fetchColumn();
        self::assertStringNotContainsString('52998224725', $context);
        self::assertStringNotContainsString('carlos@exemplo.com', $context);
        self::assertStringContainsString('[REDACTED]', $context);
    }

    public function testOnlyOneActiveSindicoPerCondominium(): void
    {
        $user = $this->user([$this->alpha, $this->beta]);
        $service = new RegistryService($this->pdo);
        $member = ['condominium_id' => $this->alpha, 'role' => 'sindico', 'name' => 'Marta', 'term_starts_on' => '2026-01-01', 'term_ends_on' => '2027-12-31'];
        $first = $service->save($user, 'board', $member);
        try {
            $service->save($user, 'board', ['name' => 'João'] + $member);
            self::fail('Dois síndicos ativos no mesmo condomínio.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('role', $exception->errors);
        }
        $service->save($user, 'board', ['condominium_id' => $this->beta, 'name' => 'João'] + $member);
        $service->save($user, 'board', ['role' => 'fiscal', 'name' => 'Paula'] + $member);
        $service->save($user, 'board', ['status' => 'inactive'] + $member, $first['id']);
        $service->save($user, 'board', ['name' => 'Novo síndico'] + $member);
        self::assertSame(3, $service->list($user, 'board', ['condominium_id' => $this->alpha])['total']);
    }

    public function testSharedContactsNeedGlobalAccessToWrite(): void
    {
        $linked = $this->user([$this->alpha]);
        $global = $this->user([], true);
        $service = new RegistryService($this->pdo);
        $contact = ['service' => 'emergencia', 'name' => 'Bombeiros', 'phone' => '193'];

        try {
            $service->save($linked, 'contacts', $contact);
            self::fail('Usuário sem acesso global criou contato para todos os condomínios.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('condominium_id', $exception->errors);
        }
        $shared = $service->save($global, 'contacts', $contact);
        $own = $service->save($linked, 'contacts', ['condominium_id' => $this->alpha, 'name' => 'Zelador'] + $contact);
        $service->save($global, 'contacts', ['condominium_id' => $this->beta, 'name' => 'Portaria Beta'] + $contact);

        self::assertSame(['Bombeiros', 'Zelador'], array_column($service->list($linked, 'contacts')['data'], 'name'), 'Vê os compartilhados e os do próprio condomínio.');
        self::assertFalse($service->canWrite($linked, 'contacts', $service->find($linked, 'contacts', $shared['id'])));
        $this->expectException(RegistryForbidden::class);
        $service->delete($linked, 'contacts', $shared['id']);
        unset($own);
    }

    public function testAdministradoraRegistriesIgnoreCondominiumScopeAndFilterBySearch(): void
    {
        $user = $this->user([]);
        $service = new RegistryService($this->pdo);
        $service->save($user, 'suppliers', ['legal_name' => 'Elevadores Sul Ltda', 'document' => '11.222.333/0001-81', 'category' => 'elevadores']);
        $service->save($user, 'suppliers', ['legal_name' => 'Limpa Tudo', 'document' => '529.982.247-25', 'category' => 'limpeza', 'status' => 'inactive']);

        self::assertSame(2, $service->list($user, 'suppliers')['total'], 'Fornecedor é da administradora, sem condomínio.');
        self::assertSame(['Elevadores Sul Ltda'], array_column($service->list($user, 'suppliers', ['q' => '11.222.333'])['data'], 'legal_name'));
        self::assertSame(['Limpa Tudo'], array_column($service->list($user, 'suppliers', ['status' => 'inactive'])['data'], 'legal_name'));
        self::assertSame(['Elevadores Sul Ltda'], array_column($service->list($user, 'suppliers', ['filter' => 'elevadores'])['data'], 'legal_name'));
        self::assertSame(0, $service->list($user, 'suppliers', ['q' => "%' OR 'a'='a' -- "])['total'], 'Busca é parametrizada.');
    }

    public function testPaginationIsServerSide(): void
    {
        $user = $this->user([$this->alpha]);
        $service = new RegistryService($this->pdo);
        for ($i = 1; $i <= RegistryService::PER_PAGE + 2; $i++) {
            $service->save($user, 'pets', ['condominium_id' => $this->alpha, 'unit_label' => 'A-' . $i, 'name' => sprintf('Pet %02d', $i), 'species' => 'cao']);
        }
        $second = $service->list($user, 'pets', ['page' => 2]);
        self::assertSame(2, $second['pages']);
        self::assertCount(2, $second['data']);
        self::assertSame('Pet 26', $second['data'][0]['name']);
        self::assertSame(2, $service->list($user, 'pets', ['page' => 99])['page'], 'Página fora do intervalo cai na última.');
    }

    public function testRegistryPermissionsAreSeededAndDenyByDefault(): void
    {
        $this->seedRegistryPermissions();
        $user = (new User())->findById($this->createUser(['level' => 10]));
        foreach (Definitions::all() as $definition) {
            self::assertFalse(Access::can($definition['permission'] . '.view', $user), 'Nível alto sem papel não abre cadastro.');
        }
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM access_permissions WHERE group_name = 'ERP Cadastros'")->fetchColumn();
        self::assertSame(18, $count);
    }

    public function testApiEnforcesPermissionsAndReturnsValidationErrors(): void
    {
        $viewer = $this->user([$this->alpha], false, ['erp.access', 'erp.pets.view']);
        $_SESSION['authUser'] = $viewer->id;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['condominium_id' => (string)$this->alpha, 'unit_label' => 'A-1', 'name' => 'Rex', 'species' => 'cao'];
        [$status, $body] = $this->call(fn () => (new RegistryApi())->store('pets'));
        self::assertSame(403, $status, 'Ver não dá direito a gravar.');
        self::assertSame('forbidden', $body['error']['code']);

        $manager = $this->user([$this->alpha], false, ['erp.access', 'erp.pets.view', 'erp.pets.manage']);
        $_SESSION['authUser'] = $manager->id;
        $_POST['species'] = 'dragao';
        [$status, $body] = $this->call(fn () => (new RegistryApi())->store('pets'));
        self::assertSame(422, $status);
        self::assertArrayHasKey('species', $body['error']['fields']);

        $_POST['species'] = 'cao';
        [$status, $body] = $this->call(fn () => (new RegistryApi())->store('pets'));
        self::assertSame(201, $status);
        self::assertSame('Rex', $body['data']['name']);
        self::assertFalse($body['data']['vaccinated']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['authUser'] = $viewer->id;
        [$status, $body] = $this->call(fn () => (new RegistryApi())->index('pets'));
        self::assertSame(200, $status);
        self::assertSame(1, $body['meta']['total']);

        $outsider = $this->user([$this->beta], false, ['erp.access', 'erp.pets.view']);
        $_SESSION['authUser'] = $outsider->id;
        [$status] = $this->call(fn () => (new RegistryApi())->show('pets', ['id' => (string)$body['data'][0]['id']]));
        self::assertSame(404, $status, 'Fora do escopo responde 404.');
        $_POST = [];
    }

    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            self::fail('Registro fora do escopo foi encontrado.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(callable $action): array
    {
        Access::clear();
        http_response_code(200);
        ob_start();
        $action();
        $output = (string)ob_get_clean();
        return [(int)http_response_code(), json_decode($output, true) ?? []];
    }

    private function employee(int $condominium): array
    {
        return ['condominium_id' => (string)$condominium, 'job' => 'porteiro', 'name' => 'Ana Souza', 'document' => '529.982.247-25', 'hired_on' => '2025-02-10', 'salary_cents' => '2.150,50'];
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Usuário vinculado aos condomínios dados; $global concede erp.condominiums.all.
     *
     * @param list<int> $condominiums
     * @param list<string> $permissions
     */
    private function user(array $condominiums, bool $global = false, array $permissions = []): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $permissions[] = CondominiumScope::ALL_PERMISSION;
        }
        if ($permissions !== []) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            foreach ($permissions as $permission) {
                $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$permission, $permission]);
                $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = ?')->execute([$role, $permission]);
            }
        }
        Access::clear();
        return (new User())->findById($id);
    }

    private function seedRegistryPermissions(): void
    {
        $this->pdo->exec((string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0202_erp_registry_permissions.sql'));
    }
}
