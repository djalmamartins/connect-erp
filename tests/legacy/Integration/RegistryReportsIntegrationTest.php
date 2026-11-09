<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\RegistryReports;

/** Relatórios cadastrais e administrativos (ADR 0084): pessoas mascaradas, unidades, logs só globais e escopo. */
final class RegistryReportsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private RegistryReports $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->reports = new RegistryReports($this->pdo);
        $unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'relationship' => 'owner', 'name' => 'Bia Moradora',
            'document' => self::validCpf(), 'email' => 'bia@morador.test', 'phone' => '11987654321']);
    }

    public function testPeopleAreMaskedAndUnitsCountThem(): void
    {
        $people = $this->reports->people($this->admin, $this->alpha);
        self::assertSame('Bia Moradora', $people['rows'][0]['name']);
        self::assertMatchesRegularExpression('/^\*{9}\d{2}$/', $people['rows'][0]['document']);
        self::assertSame(['b**@morador.test', '*******4321'], [$people['rows'][0]['email'], $people['rows'][0]['phone']]);
        $units = $this->reports->units($this->admin, $this->alpha);
        self::assertSame([1, 1], [$units['rows'][0]['people'], $units['totals']['without_fraction']]);
        self::assertSame('Alpha', $this->reports->accessPeople($this->admin, $this->alpha)['rows'][0]['condominium_name']);
    }

    public function testLogsNeedGlobalAccessAndScopeHidesOthers(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        self::assertSame([], $this->reports->people($outsider, null)['rows']);
        try {
            $this->reports->logs($this->admin, date('Y-m-d'), date('Y-m-d'));
            self::fail('Mostrou logs sem acesso a todos os condomínios.');
        } catch (RegistryForbidden) {
            self::addToAssertionCount(1);
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->reports->units($outsider, $this->alpha);
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
