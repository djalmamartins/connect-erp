<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use PDO;
use RuntimeException;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\PatrimonialImports;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Ano contábil de exemplo no condomínio "EXEMPLO Residencial Aurora" (ADR 0173), para que Balanço, DRE, Razão,
 * Razão bancário, Prestação de contas, Balancete anual e Inadimplência mostrem números no laboratório.
 * Roda depois do LabSampleData::create() e com a mesma trava (LabSampleData::guard, no comando).
 *
 * Todo movimento passa pelos services, para as validações e a auditoria rodarem: lançamentos pelo
 * RegistryService, saldo inicial pelo ChartOpenings, apropriação, uso do fundo e estorno pelo
 * PatrimonialEntries, aplicação e resgate pelo Investments, acordo pelo Agreements e a importação pelo
 * PatrimonialImports. Só o cadastro de apoio (contas do plano com a marca, a conta de aplicação e o saldo de
 * abertura da conta movimento) é gravado direto, como o ADR 0162 já fazia.
 *
 * Idempotente: cada passo procura antes o seu marcador (descrição com "EXEMPLO", a chave da importação, as
 * observações do acordo) e só cria o que falta. Rodar de novo não duplica nada.
 */
final class LabSampleYear
{
    public const MARK = LabSampleData::MARK;
    public const IMPORT_KEY = 'EXEMPLO-ano-lote-1';
    public const AGREEMENT_NOTE = 'EXEMPLO acordo de laboratório';
    private const CONDOMINIUM = LabSampleData::MARK . ' Residencial Aurora';
    private const UNITS = ['101', '102', '103', '104', '105', '106'];
    /** Contas próprias do condomínio de exemplo (a taxa é a 9.99 do ADR 0162). */
    private const ACCOUNTS = [
        'fund' => ['1.91', 'Taxa de fundo de reserva', 'receita'],
        'yield' => ['1.92', 'Rendimentos de aplicação', 'receita'],
        'energy' => ['2.91', 'Energia elétrica', 'despesa'],
        'cleaning' => ['2.92', 'Limpeza e conservação', 'despesa'],
        'tax' => ['2.93', 'IR sobre aplicação', 'despesa'],
    ];

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $created = [];

    public function __construct(PDO $pdo, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo;
        $this->scope = $scope ?? new CondominiumScope($pdo);
        $this->registry = new RegistryService($pdo, $this->scope);
    }

    /**
     * O ano (regra pura): até julho usa o ano anterior inteiro; de agosto em diante, o ano corrente até o mês
     * passado. Assim há sempre de 7 a 12 meses completos, todos antes de hoje.
     *
     * @return array{year:int, start:string, months:list<string>, opening:array<string,int>, monthly:array<string,int>, events:array<string,mixed>}
     */
    public static function plan(string $today): array
    {
        $date = new \DateTimeImmutable($today);
        $current = (int)$date->format('Y');
        $year = (int)$date->format('n') >= 8 ? $current : $current - 1;
        $last = $year === $current ? (int)$date->format('n') - 1 : 12;
        $months = [];
        for ($m = 1; $m <= $last; $m++) {
            $months[] = sprintf('%04d-%02d', $year, $m);
        }
        $count = count($months);
        return [
            'year' => $year,
            'start' => sprintf('%04d-01-01', $year),
            'months' => $months,
            // Banco 50.000,00 = fundo de reserva 10.000,00 + resultados acumulados 40.000,00: o balanço abre fechado.
            'opening' => ['bank' => 5000000, 'fund' => 1000000, 'equity' => 4000000],
            'monthly' => ['fee' => 65000, 'fund' => 6500, 'energy' => 120000, 'cleaning' => 150000, 'yield' => 16000, 'tax' => 2400],
            'events' => [
                'apply' => ['month' => $months[1], 'amount_cents' => 2000000],
                'redeem' => ['month' => $months[$count - 2], 'amount_cents' => 500000],
                'fund_use' => ['month' => $months[4], 'amount_cents' => 200000],
                'wrong' => ['month' => $months[5], 'amount_cents' => 30000],
                'import' => ['month' => $months[$count - 1], 'amount_cents' => 100000],
                // A unidade 106 deixa de pagar os 3 últimos meses; a limpeza do último mês fica a pagar.
                'unpaid_unit' => '106', 'unpaid_months' => array_slice($months, -3),
            ],
        ];
    }

    /** @return array<string, int> quantos registros foram criados por tipo (0 em tudo na segunda vez) */
    public function create(User $user, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $plan = self::plan($today);
        $this->created = ['accounts' => 0, 'openings' => 0, 'entries' => 0, 'patrimonial' => 0, 'transfers' => 0, 'agreements' => 0, 'imports' => 0];
        $condominium = $this->condominium();
        if (!$this->scope->canAccess($user, $condominium)) {
            throw new DomainException('O usuário escolhido não acessa o condomínio de exemplo. Use um usuário com acesso a todos os condomínios.');
        }
        $accounts = $this->accounts($condominium);
        [$checking, $investment] = $this->banks($condominium, $plan);
        $units = $this->units($condominium);

        $openings = new ChartOpenings($this->pdo, $this->scope);
        foreach (['8.2' => $plan['opening']['fund'], '9.1' => $plan['opening']['equity']] as $code => $amount) {
            if ($this->id('SELECT id FROM erp_chart_account_openings WHERE condominium_id = ? AND chart_account_id = ? AND amount_cents = ? AND opened_on = ?',
                [$condominium, $accounts[$code], $amount, $plan['start']]) === null) {
                $openings->set($user, $condominium, $accounts[$code], $plan['start'], number_format($amount / 100, 2, ',', ''), self::MARK . ' saldo de abertura');
                $this->created['openings']++;
            }
        }

        $patrimonial = new PatrimonialEntries($this->pdo, $this->scope);
        $monthly = $plan['monthly'];
        $events = $plan['events'];
        foreach ($plan['months'] as $month) {
            foreach (self::UNITS as $unit) {
                $unpaid = $unit === $events['unpaid_unit'] && in_array($month, $events['unpaid_months'], true);
                $this->entry($user, $condominium, "Taxa condominial {$month} — {$unit}", 'receita', $accounts['fee'], $monthly['fee'], $month, "{$month}-10",
                    $unpaid ? null : "{$month}-10", $checking, $units[$unit]);
            }
            $fund = $this->entry($user, $condominium, "Taxa de fundo de reserva {$month}", 'receita', $accounts['fund'], $monthly['fund'] * count(self::UNITS),
                $month, "{$month}-10", "{$month}-10", $checking);
            $this->patrimonial($user, $patrimonial, $condominium, "Apropriação ao fundo de reserva {$month}", "{$month}-10", $monthly['fund'] * count(self::UNITS),
                $accounts['9.2'], $accounts['8.2'], $fund, $checking, $today);
            $energy = $monthly['energy'] + 1000 * (int)substr($month, 5, 2);
            $this->entry($user, $condominium, "Energia elétrica {$month}", 'despesa', $accounts['energy'], $energy, $month, "{$month}-20", "{$month}-20", $checking);
            $lastMonth = $month === $plan['months'][count($plan['months']) - 1];
            $this->entry($user, $condominium, "Limpeza e conservação {$month}", 'despesa', $accounts['cleaning'], $monthly['cleaning'], $month, "{$month}-15",
                $lastMonth ? null : "{$month}-15", $checking);
        }

        // Uso do fundo: a pintura do hall sai do caixa e o fundo cobre o gasto (8.2 -> 9.2).
        $use = $events['fund_use'];
        $paint = $this->entry($user, $condominium, "Pintura do hall {$use['month']} (paga com o fundo)", 'despesa', $accounts['cleaning'], $use['amount_cents'],
            $use['month'], "{$use['month']}-25", "{$use['month']}-25", $checking);
        $this->patrimonial($user, $patrimonial, $condominium, "Uso do fundo de reserva na pintura do hall {$use['month']}", "{$use['month']}-25", $use['amount_cents'],
            $accounts['8.2'], $accounts['9.2'], $paint, null, $today);

        // Lançamento errado e o estorno dele.
        $wrong = $events['wrong'];
        $wrongId = $this->patrimonial($user, $patrimonial, $condominium, "Transferência entre fundos lançada por engano {$wrong['month']}", "{$wrong['month']}-12",
            $wrong['amount_cents'], $accounts['8.2'], $accounts['8.3'], null, null, $today);
        if ($this->id('SELECT id FROM erp_patrimonial_entries WHERE reversal_of_id = ?', [$wrongId]) === null) {
            $patrimonial->reverse($user, $wrongId, self::MARK . ' lançado na conta errada', "{$wrong['month']}-13", $today);
            $this->created['patrimonial']++;
        }

        // Aplicação ligada à conta movimento: aplica, rende todo mês (com IR) e resgata uma parte.
        $investments = new Investments($this->pdo, $this->scope);
        if ($this->id('SELECT parent_bank_account_id FROM erp_bank_accounts WHERE id = ?', [$investment]) !== $checking) {
            $investments->link($user, $investment, $checking);
        }
        $this->transfer($user, $investments, 'apply', $condominium, $investment, self::MARK . " Aplicação no CDB {$events['apply']['month']}",
            $events['apply']['amount_cents'], "{$events['apply']['month']}-05");
        foreach ($plan['months'] as $month) {
            if ($month <= $events['apply']['month']) {
                continue;
            }
            $end = (new \DateTimeImmutable("{$month}-01"))->format('Y-m-t');
            $this->entry($user, $condominium, "Rendimento do CDB {$month}", 'receita', $accounts['yield'], $monthly['yield'], $month, $end, $end, $investment);
            $this->entry($user, $condominium, "IR sobre o rendimento do CDB {$month}", 'despesa', $accounts['tax'], $monthly['tax'], $month, $end, $end, $investment);
        }
        $this->transfer($user, $investments, 'redeem', $condominium, $investment, self::MARK . " Resgate do CDB {$events['redeem']['month']}",
            $events['redeem']['amount_cents'], "{$events['redeem']['month']}-05");

        $this->agreement($user, $condominium, $units['103'], $accounts['fee'], $today);
        $this->import($user, $condominium, $events['import'], $today);
        return $this->created;
    }

    /** Lançamento pelo RegistryService, uma vez só por descrição. */
    private function entry(User $user, int $condominium, string $text, string $nature, int $account, int $cents, string $competence, string $due, ?string $paid,
        int $bank, ?int $unit = null): int
    {
        $description = self::MARK . ' ' . $text;
        $id = $this->id('SELECT id FROM erp_entries WHERE condominium_id = ? AND description = ? AND deleted_at IS NULL', [$condominium, $description]);
        if ($id !== null) {
            return $id;
        }
        $saved = $this->registry->save($user, 'entries', array_filter([
            'condominium_id' => $condominium, 'nature' => $nature, 'chart_account_id' => $account, 'description' => $description,
            'amount_cents' => number_format($cents / 100, 2, ',', ''), 'competence' => $competence, 'due_on' => $due, 'paid_on' => $paid,
            'bank_account_id' => $paid === null ? null : $bank, 'unit_id' => $unit,
        ], static fn (mixed $v): bool => $v !== null));
        $this->created['entries']++;
        return (int)$saved['id'];
    }

    private function patrimonial(User $user, PatrimonialEntries $service, int $condominium, string $text, string $date, int $cents, int $debit, int $credit,
        ?int $linked, ?int $bank, string $today): int
    {
        $description = self::MARK . ' ' . $text;
        $id = $this->id('SELECT id FROM erp_patrimonial_entries WHERE condominium_id = ? AND description = ? AND reversal_of_id IS NULL', [$condominium, $description]);
        if ($id !== null) {
            return $id;
        }
        $saved = $service->create($user, array_filter(['condominium_id' => $condominium, 'entry_date' => $date, 'description' => $description, 'amount_cents' => $cents,
            'debit_account_id' => $debit, 'credit_account_id' => $credit, 'linked_entry_id' => $linked, 'bank_account_id' => $bank],
            static fn (mixed $v): bool => $v !== null), $today);
        $this->created['patrimonial']++;
        return (int)$saved['id'];
    }

    private function transfer(User $user, Investments $investments, string $direction, int $condominium, int $investment, string $description, int $cents, string $on): void
    {
        if ($this->id("SELECT id FROM erp_bank_transfers WHERE condominium_id = ? AND description = ? AND status = 'active'", [$condominium, $description]) !== null) {
            return;
        }
        $input = ['amount_cents' => number_format($cents / 100, 2, ',', ''), 'transferred_on' => $on, 'description' => $description];
        $direction === 'apply' ? $investments->apply($user, $investment, $input) : $investments->redeem($user, $investment, $input);
        $this->created['transfers']++;
    }

    /** Acordo da unidade 103 sobre a conta vencida há 120 dias do ADR 0162 (a candidata a acordo). */
    private function agreement(User $user, int $condominium, int $unit, int $account, string $today): void
    {
        if ($this->id("SELECT id FROM erp_agreements WHERE condominium_id = ? AND unit_id = ? AND notes = ? AND status = 'active'", [$condominium, $unit, self::AGREEMENT_NOTE]) !== null) {
            return;
        }
        $ids = array_map('intval', $this->column("SELECT id FROM erp_entries WHERE condominium_id = ? AND unit_id = ? AND nature = 'receita' AND status = 'active'
            AND paid_on IS NULL AND agreement_id IS NULL AND deleted_at IS NULL AND due_on < ? AND description LIKE ? ORDER BY due_on LIMIT 1",
            [$condominium, $unit, $today, self::MARK . ' Taxa condominial 103 (%']));
        if ($ids === []) {
            return;   // a conta do ADR 0162 já foi paga ou renegociada à mão: não inventa outra
        }
        $first = (new \DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
        (new Agreements($this->pdo, $this->scope))->create($user, ['unit_id' => $unit, 'entry_ids' => $ids, 'fees_cents' => '13,00', 'discount_cents' => 0,
            'installments' => 3, 'first_due_on' => $first, 'chart_account_id' => $account, 'notes' => self::AGREEMENT_NOTE]);
        $this->created['agreements']++;
    }

    /** Uma importação patrimonial aplicada (8.2 -> 8.3), com chave fixa: a segunda vez acha a mesma. */
    private function import(User $user, int $condominium, array $event, string $today): void
    {
        $existing = $this->id("SELECT id FROM erp_patrimonial_imports WHERE condominium_id = ? AND idempotency_key = ? AND status = 'applied'", [$condominium, self::IMPORT_KEY]);
        if ($existing !== null) {
            return;
        }
        $file = tempnam(sys_get_temp_dir(), 'exemplo');
        if ($file === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário da importação de exemplo.');
        }
        try {
            $value = number_format($event['amount_cents'] / 100, 2, ',', '');
            file_put_contents($file, "data;descricao;valor;conta_debito;conta_credito\n"
                . (new \DateTimeImmutable("{$event['month']}-01"))->format('d/m/Y') . ';' . self::MARK . " Reserva para obras {$event['month']};{$value};8.2;8.3\n");
            $imports = new PatrimonialImports($this->pdo, $this->scope, true);
            $preview = $imports->preview($user, ['condominium_id' => $condominium, 'idempotency_key' => self::IMPORT_KEY],
                ['name' => 'exemplo-patrimonial.csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK], $today);
            if (($preview['status'] ?? '') !== 'applied') {
                $imports->apply($user, (int)$preview['id'], $today);
                $this->created['imports']++;
            }
        } finally {
            @unlink($file);
        }
    }

    private function condominium(): int
    {
        $id = $this->id('SELECT id FROM condominiums WHERE name = ?', [self::CONDOMINIUM]);
        if ($id === null) {
            throw new DomainException('Crie antes os dados de exemplo do ADR 0162 (LabSampleData::create).');
        }
        return $id;
    }

    /** @return array<string, int> id por chave (fund, yield, energy, cleaning, tax, fee) e por código patrimonial (8.2, 8.3, 9.1, 9.2) */
    private function accounts(int $condominium): array
    {
        $ids = [];
        foreach (['8.2', '8.3', '9.1', '9.2'] as $code) {
            $id = $this->id("SELECT id FROM erp_chart_accounts WHERE condominium_id IS NULL AND code = ? AND kind = 'analitica' AND status = 'active' AND deleted_at IS NULL", [$code]);
            if ($id === null) {
                throw new DomainException("Falta a conta {$code} do plano padrão: rode a migration 20261109_0704 antes.");
            }
            $ids[$code] = $id;
        }
        $fee = $this->id('SELECT id FROM erp_chart_accounts WHERE condominium_id = ? AND name = ? AND deleted_at IS NULL', [$condominium, self::MARK . ' Taxa condominial']);
        if ($fee === null) {
            throw new DomainException('Crie antes os dados de exemplo do ADR 0162 (LabSampleData::create).');
        }
        $ids['fee'] = $fee;
        foreach (self::ACCOUNTS as $key => [$code, $name, $nature]) {
            $label = self::MARK . ' ' . $name;
            $id = $this->id('SELECT id FROM erp_chart_accounts WHERE condominium_id = ? AND name = ? AND deleted_at IS NULL', [$condominium, $label]);
            if ($id === null) {
                $this->pdo->prepare("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (?, ?, ?, ?, 'analitica')")
                    ->execute([$condominium, $code, $label, $nature]);
                $id = (int)$this->pdo->lastInsertId();
                $this->created['accounts']++;
            }
            $ids[$key] = $id;
        }
        return $ids;
    }

    /** @return array{0:int, 1:int} conta movimento (com o saldo de abertura) e a aplicação */
    private function banks(int $condominium, array $plan): array
    {
        $checking = $this->id('SELECT id FROM erp_bank_accounts WHERE condominium_id = ? AND name = ?', [$condominium, self::MARK . ' Conta movimento']);
        if ($checking === null) {
            throw new DomainException('Crie antes os dados de exemplo do ADR 0162 (LabSampleData::create).');
        }
        $this->pdo->prepare('UPDATE erp_bank_accounts SET opening_balance_cents = ?, opened_on = ? WHERE id = ? AND (opened_on IS NULL OR opened_on <> ? OR opening_balance_cents <> ?)')
            ->execute([$plan['opening']['bank'], $plan['start'], $checking, $plan['start'], $plan['opening']['bank']]);
        $name = self::MARK . ' Aplicação CDB';
        $investment = $this->id('SELECT id FROM erp_bank_accounts WHERE condominium_id = ? AND name = ?', [$condominium, $name]);
        if ($investment === null) {
            $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number, kind, opening_balance_cents, opened_on)
                VALUES (?, ?, '000', '0000', '00000-1', 'aplicacao', 0, ?)")->execute([$condominium, $name, $plan['start']]);
            $investment = (int)$this->pdo->lastInsertId();
            $this->created['accounts']++;
        }
        return [$checking, $investment];
    }

    /** @return array<string, int> */
    private function units(int $condominium): array
    {
        $units = [];
        foreach (self::UNITS as $number) {
            $id = $this->id('SELECT id FROM erp_units WHERE condominium_id = ? AND number = ? AND deleted_at IS NULL', [$condominium, $number]);
            if ($id === null) {
                throw new DomainException("Falta a unidade {$number} do condomínio de exemplo (LabSampleData::create).");
            }
            $units[$number] = $id;
        }
        return $units;
    }

    private function id(string $sql, array $params): ?int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int)$value;
    }

    /** @return list<mixed> */
    private function column(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
