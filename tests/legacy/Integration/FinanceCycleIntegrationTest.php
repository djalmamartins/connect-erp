<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Ciclo financeiro de ponta a ponta (ciclos 8 a 16), encadeando os serviços como o operador usa:
 * fatura → boleto → retorno do banco dá baixa → acordo da unidade em atraso → fechamento da
 * competência → prestação de contas. No fim, um usuário de outro condomínio não alcança nada.
 * Cada serviço tem o próprio teste; este confere que a saída de um é a entrada certa do próximo.
 */
final class FinanceCycleIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
    }

    public function testInvoiceToStatementAndScope(): void
    {
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $agreementAccount = $this->service->save($this->admin, 'chart', ['code' => '1.02', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Acordos'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        $units = [];
        foreach (['101', '102'] as $number) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];

        // 1. Fatura de setembro: uma conta a receber por unidade.
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type,
            'competence' => '2026-09', 'due_on' => '2026-09-10'])['id']);
        $entries = array_column($invoice['items'], 'entry_id', 'unit_label');
        self::assertCount(2, $entries);

        // 2. Boleto da 101 e retorno do banco pagando o valor cheio.
        $charges = new BankCharges($this->pdo);
        $charge = $charges->issue($this->admin, (int)$entries['101'], $bank);
        $result = (new BankReturns($this->pdo))->process($this->admin, $bank, 'retorno-setembro.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'CICLO-1', 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => $charge['amount_cents'], 'occurred_on' => '2026-10-05'],
        ]));
        self::assertSame(['applied'], array_column($result['events'], 'outcome'));
        $paid = $this->service->find($this->admin, 'entries', (int)$entries['101']);
        self::assertSame(['2026-10-05', $bank], [$paid['paid_on'], $paid['bank_account_id']], 'Baixa com a data e a conta do retorno.');
        self::assertSame('paid', $charges->find($this->admin, $charge['id'])['status']);

        // 3. A 102 segue em atraso e vira acordo em duas parcelas.
        $agreements = new Agreements($this->pdo);
        $agreement = $agreements->create($this->admin, ['unit_id' => $units['102'], 'entry_ids' => [(int)$entries['102']], 'installments' => 2,
            'first_due_on' => '2026-12-10', 'chart_account_id' => $agreementAccount]);
        self::assertSame([50000, 2], [$agreement['original_cents'], $agreement['installments']]);
        self::assertSame(['inactive'], array_values(array_unique(array_column($agreement['originals'], 'status'))), 'A conta original sai do aberto.');

        // 4. Fechar setembro trava a competência para nova fatura.
        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-09');
        try {
            $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type, 'competence' => '2026-09', 'due_on' => '2026-09-20']);
            self::fail('Faturou competência fechada.');
        } catch (DomainException $error) {
            self::assertStringContainsString('fechada', $error->getMessage());
        }

        // 5. Prestação de contas de outubro (caixa): entra o pagamento da 101.
        $reports = new FinancialReports($this->pdo);
        $statement = $reports->statement($this->admin, $this->alpha, '2026-10-01', '2026-10-31');
        $received = array_sum(array_column(array_filter($statement['accounts'], static fn (array $a): bool => $a['nature'] === 'receita'), 'total_cents'));
        self::assertSame(50000, $received);

        // 6. Usuário só do Beta não alcança nada do Alpha.
        $outsider = $this->user([$this->beta]);
        foreach ([
            fn () => $invoices->find($outsider, (int)$invoice['id']),
            fn () => $charges->find($outsider, (int)$charge['id']),
            fn () => $agreements->find($outsider, (int)$agreement['id']),
            fn () => $reports->statement($outsider, $this->alpha, '2026-10-01', '2026-10-31'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} alcançou dado de outro condomínio.");
            } catch (RegistryNotFound|CondominiumAccessDenied) {
                // Busca por id responde 404; relatório de condomínio fora do escopo, acesso negado.
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $reports->statement($outsider, null, '2026-10-01', '2026-10-31')['accounts']);
        self::assertSame([], $reports->trialBalance($outsider, null, '2026-09', '2026-09')['rows']);
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
