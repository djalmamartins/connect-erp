<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\Registry\RegistryService;

/** Relatórios de cobrança (ADR 0081) sobre dados reais: acordos, inadimplentes, recebimentos e escopo. */
final class CollectionReportsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private CollectionReports $reports;
    private int $account;
    private int $bank;
    /** @var list<int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->reports = new CollectionReports($this->pdo);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->account = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        foreach (['101', '102'] as $number) {
            $this->units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $this->service->save($this->admin, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->units[0], 'relationship' => 'owner',
            'name' => 'Bia Moradora', 'document' => self::validCpf(), 'email' => 'bia@morador.test']);
    }

    public function testDelinquentsAgreementsAndReceipts(): void
    {
        $old = $this->entry($this->units[0], '500,00', '-50 days');
        $this->entry($this->units[1], '200,00', '-20 days');
        $paid = $this->entry($this->units[1], '300,00', '-5 days');
        $this->pdo->exec("UPDATE erp_entries SET paid_on = CURDATE(), bank_account_id = {$this->bank} WHERE id = {$paid}");

        $general = $this->reports->delinquents($this->admin, $this->alpha, null, 'general');
        self::assertSame([2, 70000], [$general['rows'][0]['units'], $general['rows'][0]['amount_cents']]);
        $resident = $this->reports->delinquents($this->admin, $this->alpha, null, 'resident');
        $byUnit = array_column($resident['rows'], 'responsible', 'unit_label');
        self::assertSame('Bia Moradora', $byUnit['101']['name']);
        self::assertMatchesRegularExpression('/^\*{9}\d{2}$/', $byUnit['101']['document'], 'Documento mascarado.');
        self::assertNull($byUnit['102'], 'Unidade sem responsável cadastrado.');

        (new Agreements($this->pdo))->create($this->admin, ['unit_id' => $this->units[0], 'entry_ids' => [$old], 'installments' => 2,
            'first_due_on' => date('Y-m-d', strtotime('+10 days')), 'chart_account_id' => $this->account, 'fees_cents' => '30,00']);
        $today = date('Y-m-d');
        $agreements = $this->reports->agreements($this->admin, $this->alpha, $today, $today);
        self::assertSame([1, 53000, 0, 53000], [$agreements['totals']['agreements'], $agreements['totals']['total_cents'], $agreements['totals']['paid_cents'], $agreements['totals']['open_cents']]);
        self::assertSame(3000, $this->reports->agreementFees($this->admin, $this->alpha, $today, $today)['totals']['net_fees_cents']);

        $receipts = $this->reports->receipts($this->admin, $this->alpha, $today, $today, 'bank_account');
        self::assertSame([['Movimento', 1, 30000]], array_map(static fn (array $r): array => [$r['account'], $r['entries'], $r['amount_cents']], $receipts['rows']));
    }

    public function testScope(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        $this->entry($this->units[0], '500,00', '-50 days');
        self::assertSame([], $this->reports->delinquents($outsider, null, null, 'unit')['rows'], 'Todos do escopo dele: nada do Alpha.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->reports->receipts($outsider, $this->alpha, date('Y-m-d'), date('Y-m-d'));
    }

    private function entry(int $unit, string $amount, string $due): int
    {
        $date = date('Y-m-d', strtotime($due));
        return $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $this->account,
            'unit_id' => $unit, 'description' => 'Taxa', 'amount_cents' => $amount, 'competence' => substr($date, 0, 7), 'due_on' => $date])['id'];
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
