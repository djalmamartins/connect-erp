<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Resident\ResidentFeed;
use Source\Services\Resident\ResidentLinks;
use Source\Services\Resident\ResidentPortal;

/** ADR 0683: o proprietário vê todas as contas da unidade; o inquilino só as que ele paga. */
final class ResidentTenantViewIntegrationTest extends TestCase
{
    private int $condo;
    private int $unit;
    private int $account;
    private int $tenantResident;
    private User $owner;
    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare("INSERT INTO condominiums (name, document, status) VALUES ('Alpha', '11047290000145', 'active')")->execute();
        $this->condo = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, '101', 'apartamento')")->execute([$this->condo]);
        $this->unit = (int)$this->pdo->lastInsertId();
        $adminId = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($adminId, $this->condo);
        $admin = (new User())->findById($adminId);
        foreach (array_keys(ResidentPortalPermissions::FEATURES) as $feature) {
            (new ResidentPortalPermissions($this->pdo))->set($admin, $this->condo, $feature, true);
        }
        $ownerId = $this->createUser();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, user_id) VALUES (?, ?, '101', 'owner', 'Ana Dona', ?)")
            ->execute([$this->condo, $this->unit, $ownerId]);
        $tenantId = $this->createUser();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, user_id) VALUES (?, ?, '101', 'tenant', 'Beto Inquilino', ?)")
            ->execute([$this->condo, $this->unit, $tenantId]);
        $this->tenantResident = (int)$this->pdo->lastInsertId();
        $this->owner = (new User())->findById($ownerId);
        $this->tenant = (new User())->findById($tenantId);
        $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, '1.91', 'Taxa condominial', 'receita', 'analitica')")->execute([$this->condo]);
        $this->account = (int)$this->pdo->lastInsertId();
    }

    private function link(User $user): array
    {
        return (new ResidentLinks($this->pdo))->forUser($user)[0];
    }

    private function entry(string $description, string $due, ?int $payer = null): int
    {
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, unit_id, payer_resident_id, description, amount_cents, competence, due_on)
            VALUES (?, 'receita', ?, ?, ?, ?, 50000, ?, ?)")->execute([$this->condo, $this->account, $this->unit, $payer, $description, substr($due, 0, 7), $due]);
        return (int)$this->pdo->lastInsertId();
    }

    public function testTenantSeesOnlyWhatHePaysAndOwnerSeesEverything(): void
    {
        $mine = $this->entry('Taxa do inquilino', '2026-10-10', $this->tenantResident);
        $extra = $this->entry('Fundo de obras', '2026-10-15');   // sem pagador; unidade sem "inquilino" como responsável
        $portal = new ResidentPortal($this->pdo);

        $tenantLines = array_column($portal->charges($this->link($this->tenant), '2026-01-01', '2026-12-31', '2026-11-20')['lines'], 'entry_id');
        self::assertSame([$mine], $tenantLines);
        $ownerLines = array_column($portal->charges($this->link($this->owner), '2026-01-01', '2026-12-31', '2026-11-20')['lines'], 'entry_id');
        self::assertEqualsCanonicalizing([$mine, $extra], $ownerLines);

        $summary = $portal->summary($this->link($this->tenant), '2026-11-20')['charges'];
        self::assertSame(1, $summary['open_count']);
        self::assertSame($mine, $summary['next_entry_id']);
        self::assertFalse($portal->hasChargesBefore($this->link($this->tenant), '2026-10-10'));
        self::assertTrue($portal->hasChargesBefore($this->link($this->owner), '2026-10-12'));

        $this->expectException(RegistryNotFound::class);
        $portal->entry($this->link($this->tenant), $extra, '2026-11-20');
    }

    public function testUnitWithTenantAsBillingResponsibleShowsUnassignedChargesToTheTenant(): void
    {
        $this->pdo->prepare("UPDATE erp_units SET billing_responsible = 'inquilino' WHERE id = ?")->execute([$this->unit]);
        $rent = $this->entry('Taxa condominial', '2026-10-10');
        $portal = new ResidentPortal($this->pdo);
        self::assertSame([$rent], array_column($portal->charges($this->link($this->tenant), '2026-01-01', '2026-12-31', '2026-11-20')['lines'], 'entry_id'));
        $bell = array_column((new ResidentFeed($this->pdo))->notifications($this->link($this->tenant), '2026-11-20'), 'kind');
        self::assertContains('charge', $bell);
    }

    public function testBellDoesNotWarnTheTenantAboutTheOwnersCharges(): void
    {
        $this->entry('Fundo de obras', '2026-10-15');
        $bell = array_column((new ResidentFeed($this->pdo))->notifications($this->link($this->tenant), '2026-11-20'), 'kind');
        self::assertNotContains('charge', $bell);
        $ownerBell = array_column((new ResidentFeed($this->pdo))->notifications($this->link($this->owner), '2026-11-20'), 'kind');
        self::assertContains('charge', $ownerBell);
    }

    public function testOwnerOnlyFeatures(): void
    {
        $tenant = $this->link($this->tenant)['features'];
        $owner = $this->link($this->owner)['features'];
        foreach (['accountability', 'assemblies'] as $feature) {
            self::assertContains($feature, $owner);
            self::assertNotContains($feature, $tenant);
        }
        foreach (['boletos', 'reservations', 'occurrences', 'announcements', 'deliveries', 'visitors', 'documents'] as $feature) {
            self::assertContains($feature, $tenant, "Inquilino com {$feature}");
        }
    }
}
