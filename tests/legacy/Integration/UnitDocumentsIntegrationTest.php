<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitDocuments;

/** Certidão, declaração, quitação e anuência: regras, foto do conteúdo, verificação e escopo (ADR 0044). */
final class UnitDocumentsIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private UnitDocuments $documents;
    private int $unit;
    private int $fee;
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->documents = new UnitDocuments($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
    }

    public function testClearUnitGetsCertificateQuittanceAndConsent(): void
    {
        $this->debt('2026-09', '2026-09-10', '2026-09-10');
        $this->debt('2026-10', '2026-10-10', '2026-10-09');
        $certificate = $this->documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $this->unit, 'as_of' => '2026-11-09']);
        self::assertSame(['clear', 'issued', 0], [$certificate['result'], $certificate['status'], $certificate['total_cents']], 'Nada consta.');
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $certificate['verification_display']);

        $quittance = $this->documents->issue($this->admin, ['kind' => 'quittance', 'unit_id' => $this->unit, 'as_of' => '2026-11-09', 'until_competence' => '2026-10']);
        self::assertSame([2, 100000], [$quittance['content']['paid_entries'], $quittance['content']['paid_cents']]);
        self::assertSame('Venda da unidade', $this->documents->issue($this->admin, ['kind' => 'consent_letter', 'unit_id' => $this->unit,
            'as_of' => '2026-11-09', 'purpose' => 'Venda da unidade'])['reference']);

        try {
            $this->documents->issue($this->admin, ['kind' => 'delinquency_declaration', 'unit_id' => $this->unit, 'as_of' => '2026-11-09']);
            self::fail('Declarou inadimplência de unidade em dia.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($certificate['id'], $this->documents->verify($this->admin, strtolower($certificate['verification_display']))['id']);
    }

    public function testUnitWithDebtsAndCancel(): void
    {
        $this->debt('2026-09', '2026-09-10');
        $this->debt('2026-11', '2026-11-20');   // a vencer
        $certificate = $this->documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $this->unit, 'as_of' => '2026-11-09']);
        self::assertSame('debts', $certificate['result']);
        self::assertCount(1, $certificate['content']['overdue']);
        self::assertCount(1, $certificate['content']['upcoming']);
        self::assertGreaterThan(50000, $certificate['total_cents'], 'Vencida com encargos.');
        self::assertSame('debts', $this->documents->issue($this->admin, ['kind' => 'delinquency_declaration', 'unit_id' => $this->unit, 'as_of' => '2026-11-09'])['result']);

        foreach ([
            [['kind' => 'quittance', 'until_competence' => '2026-09'], DomainException::class],
            [['kind' => 'consent_letter', 'purpose' => 'Venda da unidade'], DomainException::class],
            [['kind' => 'quittance'], InvalidArgumentException::class],
            [['kind' => 'protesto'], InvalidArgumentException::class],
            [['kind' => 'debt_certificate', 'as_of' => '2099-01-01'], InvalidArgumentException::class],
        ] as [$input, $expected]) {
            try {
                $this->documents->issue($this->admin, $input + ['unit_id' => $this->unit, 'as_of' => '2026-11-09']);
                self::fail('Emitiu documento indevido: ' . json_encode($input));
            } catch (\Throwable $error) {
                self::assertInstanceOf($expected, $error);
            }
        }

        $cancelled = $this->documents->cancel($this->admin, $certificate['id'], 'Emitida com data errada');
        self::assertSame('cancelled', $this->documents->verify($this->admin, $cancelled['verification_code'])['status'], 'A verificação mostra o cancelamento.');
        self::assertSame(2, $this->documents->list($this->admin, $this->alpha)['total']);
    }

    public function testScope(): void
    {
        $document = $this->documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $this->unit, 'as_of' => '2026-11-09']);
        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->documents->list($outsider)['total']);
        try {
            $this->documents->verify($outsider, $document['verification_code']);
            self::fail('Verificou documento fora do escopo.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
        $this->expectException(RegistryNotFound::class);
        $this->documents->issue($outsider, ['kind' => 'debt_certificate', 'unit_id' => $this->unit]);
    }

    public function testDocumentsFollowTheReferenceDateAndTheUnitCondominium(): void
    {
        // ADR 0105: paga em 15/10, a conta vencida em 10/09 estava em aberto em 01/10.
        $this->debt('2026-09', '2026-09-10', '2026-10-15');
        $before = $this->documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $this->unit, 'as_of' => '2026-10-01']);
        self::assertSame(['debts', 1], [$before['result'], count($before['content']['overdue'])], 'Certidão de 01/10 mostra a dívida da época.');
        self::assertSame('clear', $this->documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $this->unit, 'as_of' => '2026-11-09'])['result']);
        try {
            $this->documents->issue($this->admin, ['kind' => 'quittance', 'unit_id' => $this->unit, 'as_of' => '2026-10-01', 'until_competence' => '2026-09']);
            self::fail('Quitação de 01/10 com a conta ainda em aberto naquela data.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        // Dado inconsistente: conta de outro condomínio apontando para a unidade não entra.
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, unit_id)
            VALUES (?, 'receita', ?, 'Outro condomínio', 70000, '2026-08', '2026-08-10', ?)")->execute([$this->beta, $this->fee, $this->unit]);
        self::assertSame('clear', $this->documents->issue($this->admin, ['kind' => 'debt_certificate', 'unit_id' => $this->unit, 'as_of' => '2026-11-09'])['result']);
    }

    private function debt(string $competence, string $dueOn, ?string $paidOn = null): int
    {
        $input = ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $this->fee, 'amount_cents' => '500,00',
            'competence' => $competence, 'description' => 'Taxa ' . $competence, 'due_on' => $dueOn, 'unit_id' => $this->unit];
        if ($paidOn !== null) {
            $input += ['paid_on' => $paidOn, 'bank_account_id' => $this->bank];
        }
        return $this->service->save($this->admin, 'entries', $input)['id'];
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
