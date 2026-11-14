<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ResidentPortalAdoption;

/** ADR 0268: adesão ao portal do morador com os cadastros e os convites; somente leitura e sem dado pessoal. */
final class ResidentPortalAdoptionTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102', '103'] as $number) {
            $this->units[$number] = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testAdoptionFromTheRegistriesAndInvitations(): void
    {
        $accepted = $this->resident('101', 'owner', '529.982.247-25', 'dona@exemplo.test');
        $pending = $this->resident('101', 'tenant', '111.444.777-35', 'inquilino@exemplo.test');
        $this->resident('102', 'owner', null, 'semdoc@exemplo.test');
        $this->resident('103', 'owner', '529.982.247-25', null);
        $this->invitation($accepted, 'accepted', '-1 day');
        $this->invitation($pending, 'pending', '+5 days');
        $this->pdo->prepare("INSERT INTO erp_resident_portal_permissions (condominium_id, feature, enabled) VALUES (?, 'boletos', 1)")->execute([$this->condominium]);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ResidentPortalAdoption($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['accepted' => 1, 'pending' => 1, 'expired' => 0, 'revoked' => 0, 'ready' => 0, 'no_document' => 1, 'no_email' => 1],
            array_combine(array_column($report['rows'], 'situation'), array_column($report['rows'], 'residents')));
        self::assertSame(['residents' => 4, 'with_access' => 1, 'to_invite' => 0], $report['totals']);
        self::assertSame(['inhabited' => 3, 'with_access' => 1, 'without_access' => 2], $report['units']);
        self::assertSame(1, $report['enabled']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Pessoa de teste', '52998224725', '529.982', '@', 'exemplo', 'token'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ResidentPortalAdoption($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function resident(string $unit, string $relationship, ?string $document, ?string $email): int
    {
        return (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit], 'relationship' => $relationship,
            'name' => 'Pessoa de teste', 'document' => $document, 'email' => $email])['id'];
    }

    private function invitation(int $resident, string $status, string $expires): void
    {
        $this->pdo->prepare('INSERT INTO erp_resident_invitations (condominium_id, resident_id, token_hash, status, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->condominium, $resident, hash('sha256', 'teste-' . $resident), $status, date('Y-m-d H:i:s', strtotime($expires))]);
    }
}
