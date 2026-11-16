<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\RevenueLaunch;

/** Nova Receita no padrão do modelo 8 (ADR 0313): rateio, valor de cada unidade, fundo de reserva, cobrar de e repetir. */
final class RevenueLaunchTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $user;
    private RegistryService $service;
    private int $fee;
    private int $fund;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->user = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->pdo->exec("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('1.08', 'Receitas diversas', 'receita', 'analitica'), ('1.09', 'Fundo de reserva', 'receita', 'analitica')");
        $this->fee = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '1.08'")->fetchColumn();
        $this->fund = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '1.09'")->fetchColumn();
        foreach (['101', '102', '103'] as $number) {
            $this->units[$number] = (int)$this->service->save($this->user, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $this->pdo->exec("UPDATE erp_units SET apportionment_exempt = 1 WHERE id = {$this->units['103']}");
    }

    public function testAllUnitsWithReserveRepeatAndTenantAsPayer(): void
    {
        $tenant = (int)$this->service->save($this->user, 'residents', ['condominium_id' => $this->alpha, 'unit_id' => $this->units['101'], 'relationship' => 'tenant', 'name' => 'Inquilina'])['id'];
        $result = (new RevenueLaunch($this->pdo))->launch($this->user, $this->input(['apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '100,00',
            'reserve_percent' => '10', 'reserve_account_id' => $this->fund, 'payer' => 'tenant', 'repeat' => 2, 'repeat_label' => true]));

        self::assertSame([2, 4, 8], [$result['months'], count($result['charges']), count($result['entries'])], '2 meses x (taxa + fundo) x 2 unidades (a isenta fica fora).');
        $rows = $this->pdo->query("SELECT competence, due_on, unit_id, amount_cents, description, payer_resident_id, chart_account_id FROM erp_entries ORDER BY competence, chart_account_id, unit_id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['2027-01', '2027-01-31', 10000, 'Taxa da portaria (1/2) — 101', $tenant], [$rows[0]['competence'], $rows[0]['due_on'], (int)$rows[0]['amount_cents'], $rows[0]['description'], (int)$rows[0]['payer_resident_id']]);
        self::assertNull($rows[1]['payer_resident_id'], 'Unidade sem inquilino fica com o padrão.');
        self::assertSame([1000, 'Fundo de reserva — Taxa da portaria (1/2) — 101'], [(int)$rows[2]['amount_cents'], $rows[2]['description']]);
        self::assertSame(['2027-02', '2027-02-28'], [$rows[4]['competence'], $rows[4]['due_on']], 'Segundo mês, vencimento no último dia de fevereiro.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_entries WHERE unit_id = {$this->units['103']}")->fetchColumn(), 'Isenta de rateio fora.');
    }

    public function testCustomValuesNoApportionAndRefusals(): void
    {
        $launch = new RevenueLaunch($this->pdo);
        $custom = $launch->launch($this->user, $this->input(['apportion' => 'chosen', 'method' => 'custom', 'unit_ids' => [$this->units['101'], $this->units['103']],
            'unit_amounts' => [$this->units['101'] => '12,50', $this->units['103'] => '40,00']]));
        self::assertSame([1250, 4000], array_map('intval', $this->pdo->query('SELECT amount_cents FROM erp_entries WHERE extra_charge_id = ' . $custom['charges'][0] . ' ORDER BY unit_id')->fetchAll(\PDO::FETCH_COLUMN)),
            'Escolhida à mão, a isenta aceita.');
        self::assertSame('custom', $this->pdo->query('SELECT method FROM erp_extra_charges WHERE id = ' . $custom['charges'][0])->fetchColumn());

        $single = $launch->launch($this->user, $this->input(['apportion' => 'none', 'amount_cents' => '350,00', 'description' => 'Aluguel do salão', 'document_number' => 'NF 12']));
        $row = $this->pdo->query('SELECT unit_id, extra_charge_id, nature, amount_cents, document_number FROM erp_entries WHERE id = ' . $single['entries'][0])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([null, null, 'receita', 35000, 'NF 12'], [$row['unit_id'], $row['extra_charge_id'], $row['nature'], (int)$row['amount_cents'], $row['document_number']]);

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn();
        foreach ([
            ['apportion' => 'chosen', 'method' => 'custom', 'unit_ids' => [$this->units['101'], $this->units['102']], 'unit_amounts' => [$this->units['101'] => '1,00']],
            ['apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '10,00', 'reserve_percent' => '50', 'reserve_account_id' => $this->fund],
            ['apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '10,00', 'reserve_percent' => '5'],
            ['apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '10,00', 'repeat' => 25],
            ['apportion' => 'x'],
        ] as $bad) {
            try {
                $launch->launch($this->user, $this->input($bad));
                self::fail('Aceitou: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2027-02', 'closed')")->execute([$this->alpha]);
        try {
            $launch->launch($this->user, $this->input(['apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '10,00', 'repeat' => 2]));
            self::fail('Lançou no mês fechado.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($count, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'Fevereiro fechado desfaz janeiro também.');
        $this->expectException(RegistryNotFound::class);
        $launch->launch($this->user, $this->input(['condominium_id' => $this->beta, 'apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '10,00']));
    }

    public function testRelatedAccountAndRepeatIndexes(): void
    {
        $this->pdo->exec("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('2.05', 'Obras', 'despesa', 'analitica'), ('2', 'Despesas', 'despesa', 'sintetica')");
        $works = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '2.05'")->fetchColumn();
        $launch = new RevenueLaunch($this->pdo);
        $result = $launch->launch($this->user, $this->input(['apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '50,00', 'reserve_percent' => '10',
            'reserve_account_id' => $this->fund, 'related_account_id' => $works, 'repeat' => 2, 'repeat_label' => true, 'repeat_start' => '4', 'repeat_total' => '10']));
        $rows = $this->pdo->query('SELECT description, related_account_id, chart_account_id FROM erp_entries WHERE id IN (' . implode(',', $result['entries']) . ') ORDER BY competence, chart_account_id, unit_id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['Taxa da portaria (4/10) — 101', $works], [$rows[0]['description'], (int)$rows[0]['related_account_id']]);
        self::assertNull($rows[2]['related_account_id'], 'Fundo de reserva fica sem a conta relacionada.');
        self::assertSame('Taxa da portaria (5/10) — 101', $rows[4]['description']);

        $single = $launch->launch($this->user, $this->input(['apportion' => 'none', 'amount_cents' => '10,00', 'related_account_id' => (string)$works, 'repeat' => 1, 'repeat_label' => true, 'repeat_start' => 3, 'repeat_total' => 3]));
        $row = $this->pdo->query('SELECT description, related_account_id FROM erp_entries WHERE id = ' . $single['entries'][0])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['Taxa da portaria (3/3)', $works], [$row['description'], (int)$row['related_account_id']], 'Última parcela de uma série.');

        self::assertSame([1, 2], RevenueLaunch::indexes(null, '', 2));
        self::assertSame([7, 12], RevenueLaunch::indexes('7', null, 6));
        $synthetic = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '2'")->fetchColumn();
        foreach ([['repeat_start' => 0], ['repeat_start' => 'a'], ['repeat' => 3, 'repeat_start' => 5, 'repeat_total' => 6], ['related_account_id' => $synthetic], ['related_account_id' => 999999]] as $bad) {
            try {
                $launch->launch($this->user, $this->input($bad + ['apportion' => 'none', 'amount_cents' => '10,00']));
                self::fail('Aceitou: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function input(array $over): array
    {
        return $over + ['condominium_id' => $this->alpha, 'description' => 'Taxa da portaria', 'chart_account_id' => $this->fee, 'competence' => '2027-01', 'due_on' => '2027-01-31'];
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
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
