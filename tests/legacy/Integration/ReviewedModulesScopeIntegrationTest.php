<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\RegistryReports;

/** Revisão dos módulos (ADR 0096): fora do escopo dá 404 e o lote de frações não atinge outro condomínio. */
final class ReviewedModulesScopeIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $this->admin = $this->user([$this->alpha]);
        $this->registry = new RegistryService($this->pdo);
    }

    public function testOutOfScopeIsNotFoundEverywhere(): void
    {
        $global = $this->user([$this->alpha, $this->beta]);
        $betaUnit = $this->registry->save($global, 'units', ['condominium_id' => $this->beta, 'number' => '9', 'type' => 'apartamento'])['id'];
        $settings = new ErpSettings($this->pdo);
        $calls = [
            'configurações' => fn () => $settings->list($this->admin, $this->beta),
            'configuração' => fn () => $settings->set($this->admin, $this->beta, 'finance.default_due_day', 10),
            'saldo inicial' => fn () => $settings->openings($this->admin, $this->beta),
            'previsão' => fn () => $settings->budget($this->admin, $this->beta, (int)date('Y')),
            'frações' => fn () => $settings->setFractions($this->admin, $this->beta, [$betaUnit => '1']),
            'pessoas' => fn () => (new RegistryReports($this->pdo))->people($this->admin, $this->beta),
            'unidades' => fn () => (new RegistryReports($this->pdo))->units($this->admin, $this->beta),
            'inadimplência' => fn () => (new Agreements($this->pdo))->delinquency($this->admin, $this->beta),
            'acordos' => fn () => (new Agreements($this->pdo))->list($this->admin, $this->beta),
            'cobrança extra' => fn () => (new EntryShortcuts($this->pdo))->createCharge($this->admin, ['condominium_id' => $this->beta]),
            'transferência' => fn () => (new EntryShortcuts($this->pdo))->transfer($this->admin, ['condominium_id' => $this->beta]),
        ];
        foreach ($calls as $what => $call) {
            try {
                $call();
                self::fail("Aceitou {$what} fora do escopo.");
            } catch (RegistryNotFound | CondominiumAccessDenied) {
                self::addToAssertionCount(1);
            } catch (InvalidArgumentException $error) {
                // Algumas listas sem acesso devolvem vazio em vez de erro; nunca dados do outro condomínio.
                self::fail("{$what}: respondeu validação ({$error->getMessage()}) em vez de 404.");
            }
        }
        try {
            (new RegistryReports($this->pdo))->logs($this->admin, date('Y-m-01'), date('Y-m-d'));
            self::fail('Logs sem acesso global.');
        } catch (RegistryForbidden) {
            self::addToAssertionCount(1);
        }
    }

    public function testFractionBatchNeverTouchesAnotherCondominium(): void
    {
        $global = $this->user([$this->alpha, $this->beta]);
        $a1 = $this->registry->save($global, 'units', ['condominium_id' => $this->alpha, 'number' => '1', 'type' => 'apartamento'])['id'];
        $a2 = $this->registry->save($global, 'units', ['condominium_id' => $this->alpha, 'number' => '2', 'type' => 'apartamento'])['id'];
        $b1 = $this->registry->save($global, 'units', ['condominium_id' => $this->beta, 'number' => '1', 'type' => 'apartamento'])['id'];
        $this->pdo->prepare('UPDATE erp_units SET ideal_fraction = 1 WHERE id = ?')->execute([$b1]);
        $settings = new ErpSettings($this->pdo);
        try {
            $settings->setFractions($global, $this->alpha, [$a1 => '0.5', $a2 => '0.25', $b1 => '0.25']);
            self::fail('Aceitou unidade de outro condomínio no lote.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('1.000000', (string)$this->pdo->query('SELECT ideal_fraction FROM erp_units WHERE id = ' . (int)$b1)->fetchColumn(), 'Beta não mudou.');
        self::assertFalse($this->pdo->inTransaction(), 'A transação foi desfeita.');
        $settings->setFractions($global, $this->alpha, [$a1 => '0.5', $a2 => '0.5']);
        self::assertSame('1.000000', (string)$this->pdo->query('SELECT ideal_fraction FROM erp_units WHERE id = ' . (int)$b1)->fetchColumn());
        self::assertSame('0.500000', (string)$this->pdo->query('SELECT ideal_fraction FROM erp_units WHERE id = ' . (int)$a1)->fetchColumn());
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
