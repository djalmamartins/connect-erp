<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\AssemblyScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de assembleia (ADR 0145) contra o banco: a lista e a escolha da assembleia em cada tela, as unidades
 * sem presença para o registro, quem ainda vota no item aberto, os relatórios de presença e de resultados e a
 * demonstração sem permissão.
 */
final class AssemblyScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 10]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, (int)$this->pdo->lastInsertId()]);
        (new CondominiumScope($this->pdo))->grant($userId, $this->alpha);
        $this->admin = (new User())->findById($userId);
        $registry = new RegistryService($this->pdo);
        foreach (['101' => '0.600000', '102' => '0.400000'] as $number => $fraction) {
            $id = $registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
            $this->pdo->prepare('UPDATE erp_units SET ideal_fraction = ? WHERE id = ?')->execute([$fraction, $id]);
            $this->units[$number] = (int)$id;
        }
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testScreensFollowTheAssemblyLifecycle(): void
    {
        $service = new Assemblies($this->pdo);
        $first = new \DateTimeImmutable('-1 hour');
        $id = $service->create($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'ordinaria', 'mode' => 'virtual', 'title' => 'AGO 2026', 'location' => 'https://sala.test/x',
            'first_call_at' => $first->format('Y-m-d H:i'), 'items' => [['title' => 'Aprovar as contas', 'vote_basis' => 'fraction', 'quorum' => 'simple']]])['id'];
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);

        $manage = (new AssemblyScreens())->data($this->admin, 'AP-018');
        self::assertTrue($manage['assemblyReady']);
        self::assertSame([$id], array_column($manage['assemblies']['data'], 'id'));
        self::assertNull($manage['assemblyDetail'], 'A gestão abre na lista.');
        self::assertTrue($manage['canManageAssembly']);
        self::assertNull((new AssemblyScreens())->data($this->admin, 'AP-017')['assemblyDetail'], 'Votação só abre assembleia aberta.');

        // Convocar exige 8 dias; aqui a assembleia passa a convocada direto no banco para testar a tela aberta.
        $this->pdo->prepare("UPDATE erp_assemblies SET status = 'convened', convened_at = NOW() WHERE id = ?")->execute([$id]);
        $service->open($this->admin, $id);
        $service->checkIn($this->admin, $id, ['unit_id' => $this->units['101'], 'attendee_name' => 'Ana Proprietária']);

        $_GET = ['assembleia' => (string)$id];
        $manage = (new AssemblyScreens())->data($this->admin, 'AP-018');
        self::assertSame('open', $manage['assemblyDetail']['status']);
        self::assertSame([$this->units['102']], array_column($manage['assemblyUnits'], 'id'), 'Só a unidade sem presença.');
        self::assertSame(['close'], AssemblyScreens::actions('open', true, true));

        $_GET = [];
        $item = $manage['assemblyDetail']['items'][0]['id'];
        $service->startVoting($this->admin, $id, $item);
        $voting = (new AssemblyScreens())->data($this->admin, 'AP-017');
        self::assertSame($id, $voting['assemblyDetail']['id']);
        self::assertSame([['id' => $this->units['101'], 'label' => $voting['assemblyVoters'][0]['label'], 'voted' => false]], $voting['assemblyVoters']);
        $service->vote($this->admin, $id, $item, $this->units['101'], 'yes');
        self::assertTrue((new AssemblyScreens())->data($this->admin, 'AP-017')['assemblyVoters'][0]['voted']);

        $attendance = (new AssemblyScreens())->data($this->admin, 'AP-088');
        self::assertSame(['units' => 1, 'proxies' => 0, 'without_vote' => 0], $attendance['assemblyReport']['totals']);
        self::assertSame(0, $attendance['assemblyReport']['rows'][0]['overdue_cents'], 'Com erp.agreements.view, o valor vencido aparece.');

        $service->closeItem($this->admin, $id, $item);
        $service->close($this->admin, $id);
        $results = (new AssemblyScreens())->data($this->admin, 'AP-092');
        self::assertSame('closed', $results['assemblyDetail']['status']);
        self::assertNotNull($results['assemblyDetail']['minutes']);
        self::assertTrue($results['assemblyReport']['rows'][0]['approved']);
    }

    public function testOutOfScopeAssemblyAndNoPermission(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $beta = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at) VALUES (?, 'ordinaria', 'virtual', 'Outra', 'x', NOW())")->execute([$beta]);
        $other = (int)$this->pdo->lastInsertId();
        $plain = (new User())->findById($this->createUser(['level' => 1]));
        self::assertSame(['assemblyReady' => false], (new AssemblyScreens())->data($plain, 'AP-018'), 'Sem erp.assemblies.view, segue a demonstração.');

        $scoped = (new User())->findById($this->createUser(['level' => 10]));
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Gestor', 'gestor-assembleia', 50]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([(int)$scoped->id, $role]);
        $permission = $this->pdo->query("SELECT id FROM access_permissions WHERE slug = 'erp.assemblies.view'")->fetchColumn();
        if ($permission === false) {
            self::markTestSkipped('Permissão erp.assemblies.view não registrada.');
        }
        foreach (['erp.access', 'erp.assemblies.view'] as $slug) {
            $this->pdo->prepare('INSERT IGNORE INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = ?')->execute([$role, $slug]);
        }
        (new CondominiumScope($this->pdo))->grant((int)$scoped->id, $this->alpha);
        $_GET = ['assembleia' => (string)$other];
        $data = (new AssemblyScreens())->data($scoped, 'AP-092');
        self::assertTrue($data['assemblyReady']);
        self::assertSame('Assembleia não encontrada no seu escopo.', $data['assemblyError']);
        self::assertNull($data['assemblyDetail']);
        self::assertFalse($data['canManageAssembly']);
    }
}
