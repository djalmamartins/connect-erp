<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\BillingRules;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryService;

/** Regras de cobrança, índices, encargos na inadimplência e no acordo, e quebra explícita (ADR 0040). */
final class LateChargesIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private BillingRules $rules;
    private Agreements $agreements;
    private int $unit;
    private int $agreementAccount;
    /** @var list<int> */
    private array $debts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->rules = new BillingRules($this->pdo);
        $this->agreements = new Agreements($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->agreementAccount = $this->service->save($this->admin, 'chart', ['code' => '1.02', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Acordos'])['id'];
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->debts[] = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $fee,
            'amount_cents' => '1.000,00', 'competence' => '2026-09', 'description' => 'Taxa 09', 'due_on' => '2026-09-10', 'unit_id' => $this->unit])['id'];
    }

    public function testRulesFallbackOverrideAndPermissions(): void
    {
        self::assertSame('builtin', $this->rules->rule($this->alpha)['source']);
        $this->rules->save($this->admin, null, ['fine_bp' => 200, 'monthly_interest_bp' => 100, 'price_index' => 'IPCA']);
        self::assertSame('default', $this->rules->rule($this->alpha)['source']);
        $own = $this->rules->save($this->admin, $this->alpha, ['fine_bp' => 100, 'monthly_interest_bp' => 50, 'price_index' => 'NONE', 'breach_days_late' => 60]);
        self::assertSame(['condominium', 100, 'NONE', 60], [$own['source'], $own['fine_bp'], $own['price_index'], $own['breach_days_late']]);
        self::assertSame('default', $this->rules->rule($this->beta)['source'], 'Outro condomínio segue o padrão.');

        $limited = $this->user([$this->beta]);
        foreach ([fn () => $this->rules->save($limited, null, []), fn () => $this->rules->saveRates($limited, 'IPCA', ['2026-10' => 0.4])] as $call) {
            try {
                $call();
                self::fail('Quem não acessa todos alterou o padrão ou o índice.');
            } catch (RegistryForbidden) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1, $this->rules->saveRates($this->admin, 'IPCA', BillingRules::parseIndexFile("2026-10;0,44"))['saved']);
        $this->rules->saveRates($this->admin, 'IPCA', ['2026-10' => 0.40]);
        self::assertSame([['competence' => '2026-10', 'rate_percent' => 0.4]], $this->rules->listRates('IPCA'), 'Mês lançado de novo é substituído.');
    }

    public function testDelinquencyAndSuggestionCarryCharges(): void
    {
        $this->rules->saveRates($this->admin, 'IPCA', ['2026-09' => 0.5]);   // outubro sem índice
        $report = $this->agreements->delinquency($this->admin, $this->alpha, '2026-11-10');
        $line = $report['units'][0]['entries'][0]['charges'];
        self::assertSame(61, $line['days_late']);
        self::assertSame(500, $line['correction_cents']);
        self::assertSame(['2026-10'], $report['totals']['missing_index'], 'Mês sem índice sinalizado.');
        self::assertSame($line['charges_cents'], $report['totals']['charges_cents']);

        $suggestion = $this->agreements->suggestion($this->admin, $this->unit, $this->debts, '2026-11-10');
        self::assertSame($line['charges_cents'], $suggestion['fees_cents']);
        self::assertSame(100000 + $suggestion['fees_cents'], $suggestion['total_cents']);
        $agreement = $this->agreements->create($this->admin, ['unit_id' => $this->unit, 'entry_ids' => $this->debts, 'installments' => 2,
            'first_due_on' => '2026-09-15', 'chart_account_id' => $this->agreementAccount, 'fees_cents' => 1000]);
        self::assertSame(1000, $agreement['fees_cents'], 'Quem faz o acordo muda a sugestão.');
    }

    public function testBreachIsExplicitAndLeavesInstallments(): void
    {
        // Primeira parcela vencida há mais de 30 dias em relação a hoje.
        $first = (new \DateTimeImmutable('today'))->modify('-45 days')->format('Y-m-d');
        $agreement = $this->agreements->create($this->admin, ['unit_id' => $this->unit, 'entry_ids' => $this->debts, 'installments' => 3,
            'first_due_on' => $first, 'chart_account_id' => $this->agreementAccount]);
        self::assertTrue($agreement['breach']['due'], 'Regra indica quebra.');
        self::assertSame('active', $agreement['status'], 'Nada muda sozinho.');

        $broken = $this->agreements->breach($this->admin, $agreement['id'], 'Parcela 1 sem pagamento há 45 dias');
        self::assertSame(['breached', 'Parcela 1 sem pagamento há 45 dias'], [$broken['status'], $broken['breach_reason']]);
        self::assertSame(['active'], array_values(array_unique(array_column($broken['parcels'], 'status'))), 'Parcelas ficam como estão.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_agreements' AND context_json LIKE '%breached%'")->fetchColumn());

        $this->expectException(DomainException::class);
        $this->agreements->breach($this->admin, $agreement['id'], 'De novo');
    }

    public function testBreachRefusedWhenRuleDoesNotApply(): void
    {
        $agreement = $this->agreements->create($this->admin, ['unit_id' => $this->unit, 'entry_ids' => $this->debts, 'installments' => 2,
            'first_due_on' => (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d'), 'chart_account_id' => $this->agreementAccount]);
        self::assertFalse($agreement['breach']['due']);
        $this->expectException(DomainException::class);
        $this->agreements->breach($this->admin, $agreement['id'], 'Sem atraso nenhum');
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
