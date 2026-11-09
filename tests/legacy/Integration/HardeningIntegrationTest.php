<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ManagementReports;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ReportExportLimit;

/** Endurecimento (ADR 0087): auditoria sem dado pessoal, contato mascarado, extrato 404 e limite de exportação. */
final class HardeningIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testRegistryAuditHasNoPersonalDataAndResidentViewIsMasked(): void
    {
        $cpf = self::validCpf();
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->unit, 'relationship' => 'owner', 'name' => 'Bia Moradora',
            'document' => $cpf, 'email' => 'bia@morador.test', 'phone' => '11987654321']);
        $context = (string)$this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_residents' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringNotContainsString($cpf, $context, 'CPF não fica em claro na auditoria.');
        self::assertStringNotContainsString('bia@morador.test', $context);
        self::assertStringNotContainsString('11987654321', $context);
        self::assertStringContainsString('Bia Moradora', $context, 'O nome continua, para a trilha fazer sentido.');

        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $account = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $due = date('Y-m-d', strtotime('-40 days'));
        $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $account, 'unit_id' => $this->unit,
            'description' => 'Taxa', 'amount_cents' => '100,00', 'competence' => substr($due, 0, 7), 'due_on' => $due]);
        $resident = (new CollectionReports($this->pdo))->delinquents($this->admin, $this->alpha, null, 'resident')['rows'][0]['responsible'];
        self::assertSame(['b**@morador.test', '*******4321'], [$resident['email'], $resident['phone']]);
    }

    public function testAccountStatementHidesExistenceAndExportLimit(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $beta = (int)$this->pdo->lastInsertId();
        $other = $this->user([$beta]);
        $bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        foreach ([[$other, $bank], [$this->admin, 999999]] as [$user, $id]) {
            try {
                (new ManagementReports($this->pdo))->accountStatement($user, $id, date('Y-m-d'), date('Y-m-d'));
                self::fail('Mostrou extrato fora do escopo ou inexistente.');
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);   // a mesma resposta nos dois casos
            }
        }

        for ($i = 0; $i < ReportExportLimit::MAX_PER_HOUR; $i++) {
            $this->pdo->prepare("INSERT INTO system_audit_logs (users_id, action, entity, entity_id, description, context_json, severity) VALUES (?, 'export', 'erp_reports', 0, 'x', '{}', 'info')")
                ->execute([(int)$this->admin->id]);
        }
        $this->expectException(DomainException::class);
        (new ReportExportLimit($this->pdo))->assertAllowed($this->admin);
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
