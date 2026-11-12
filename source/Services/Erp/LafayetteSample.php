<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use PDO;
use Source\Models\User;
use Source\Services\CondominiumRegistry;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Support\TicketService;
use Source\Support\Audit;

/**
 * Condomínio de teste "Lafayette" (docs/process/LAFAYETTE_TEST_DATA.md): seis meses completos de operação fictícia, para conferir se
 * os números do sistema fecham entre si. Pedido do Djalma em 11/11/2026.
 *
 * create() cadastra tudo pelos services que já existem (mesmas validações, escopo e auditoria de quem usa as
 * telas): condomínio, 16 unidades com fração e área, proprietários e inquilinos, síndico e conselho,
 * funcionários, fornecedores, veículos, visitantes, animais, áreas, patrimônio, portaria, relógios de água com
 * tarifa e leitura todo mês, faturas (taxa e fundo de reserva por fração ideal), cobrança da água pelo consumo,
 * boletos no banco de testes (sandbox) e retorno com os pagamentos, contas a pagar do mês, lançamentos fixos,
 * transferência para a poupança do fundo, aplicação com rendimento e IR, cobrança extra rateada, multa avulsa,
 * desconto, inadimplência com contato de cobrança, acordo, assembleia com votação, comunicados, ocorrências,
 * reservas, mudanças, entregas, chamados, saldos de abertura do plano e fechamento dos meses mais antigos.
 *
 * verify() confere os relatórios uns contra os outros e contra somas diretas no banco. Cada conferência
 * devolve o esperado, o encontrado e se bateu; nada é escondido.
 *
 * Só dados inventados: nomes, CPF e CNPJ gerados, e-mails em example.com (domínio reservado, ninguém recebe).
 * Nenhum banco real: a conta de cobrança usa o adaptador sandbox. Recusa criar de novo se o condomínio existir.
 */
final class LafayetteSample
{
    public const NAME = 'Lafayette';
    public const MONTHS = 6;
    /** Taxa condominial mensal total (rateada pela fração ideal) e fundo de reserva (10%). */
    public const FEE_TOTAL_CENTS = 1_920_000;
    public const RESERVE_TOTAL_CENTS = 192_000;
    /** Unidade que fica 3 meses sem pagar e vira acordo; unidade que deixa os 2 últimos meses em aberto. */
    public const AGREEMENT_UNIT = 'B-302';
    public const LATE_UNIT = 'A-201';
    /** Unidade que paga um mês atrasado, por Pix, com multa avulsa. */
    public const PIX_UNIT = 'A-102';

    private PDO $pdo;
    private RegistryService $registry;
    private CondominiumScope $scope;
    private User $user;
    private string $today;
    private int $condo = 0;
    /** @var array<string,int> unidade (rótulo) => id */
    private array $units = [];
    /** @var array<string,int> código => id da conta do plano */
    private array $accounts = [];
    /** @var array<string,int> */
    private array $banks = [];
    /** @var array<string,int> */
    private array $suppliers = [];
    /** @var array<string,int> contagem do que foi criado */
    private array $created = [];

    public function __construct(PDO $pdo, User $user, ?string $today = null)
    {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->registry = new RegistryService($pdo);
        $this->scope = new CondominiumScope($pdo);
        $this->today = $today ?? date('Y-m-d');
    }

    /** Competências dos seis meses completos antes de hoje, da mais antiga para a mais recente (regra pura). */
    public static function competences(string $today, int $months = self::MONTHS): array
    {
        $first = (new \DateTimeImmutable($today))->modify('first day of this month');
        $list = [];
        for ($i = $months; $i >= 1; $i--) {
            $list[] = $first->modify("-{$i} month")->format('Y-m');
        }
        return $list;
    }

    /** CNPJ fictício com dígitos verificadores válidos, a partir de 12 dígitos (regra pura). */
    public static function cnpj(string $base): string
    {
        $digits = substr(str_pad((string)preg_replace('/\D/', '', $base), 12, '0'), 0, 12);
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += (int)$digits[$i] * $weight;
            }
            $rest = $sum % 11;
            $digits .= (string)($rest < 2 ? 0 : 11 - $rest);
        }
        return $digits;
    }

    public function condominiumId(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM condominiums WHERE name = ? ORDER BY id LIMIT 1');
        $stmt->execute([self::NAME]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** @return array<string,int> quantos registros foram criados por tipo */
    public function create(): array
    {
        if ($this->condominiumId() !== null) {
            throw new DomainException('O condomínio Lafayette já existe. Use --conferir para checar os números.');
        }
        $months = self::competences($this->today);
        try {
            return $this->build($months);
        } catch (\Throwable $error) {
            // Os services abrem as próprias transações; se algo falhar no meio, apaga o que já entrou.
            if ($this->condominiumId() !== null) {
                $this->remove();
            }
            throw $error;
        }
    }

    /** @return array<string,int> */
    private function build(array $months): array
    {
        $this->condominium($months[0]);
        $this->unitsAndPeople();
        $this->registers();
        $this->chartAndBanks($months[0]);
        $this->water($months);
        foreach ($months as $i => $competence) {
            $this->month($competence, $i, count($months));
        }
        $this->delinquency($months);
        $this->operations($months);
        $this->assembly($months);
        // Os quatro meses mais antigos ficam fechados (ADR 0031); os dois últimos seguem abertos para mexer.
        $closing = new MonthClosing($this->pdo, $this->scope);
        foreach (array_slice($months, 0, 4) as $competence) {
            $closing->close($this->user, $this->condo, $competence);
            $this->count('fechamentos');
        }
        return $this->created + ['condominium_id' => $this->condo];
    }

    // ------------------------------------------------------------------ cadastro

    private function condominium(string $firstMonth): void
    {
        $result = (new CondominiumRegistry($this->pdo, $this->scope))->create($this->user, [
            'name' => self::NAME, 'legal_name' => 'Condomínio Edifício Lafayette', 'document' => self::cnpj('475120830001'),
            'status' => 'active', 'zip_code' => '14020-260', 'street' => 'Rua dos Ipês', 'number' => '1500', 'complement' => '',
            'district' => 'Jardim Sumaré', 'city' => 'Ribeirão Preto', 'state' => 'SP', 'phone' => '(16) 3610-4400',
            'email' => 'sindico@lafayette.example.com', 'blocks_count' => '2', 'units_count' => '16',
            'contract_start_date' => $firstMonth . '-01', 'fee_due_day' => '10',
        ]);
        if (!$result['ok']) {
            throw new DomainException('Condomínio recusado: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        }
        $this->condo = (int)$result['id'];
        if (!$this->scope->hasGlobalAccess($this->user)) {
            $this->scope->grant((int)$this->user->id, $this->condo, (int)$this->user->id);
        }
        $this->count('condominios');
    }

    private function unitsAndPeople(): void
    {
        // Cadastro rápido (ADR 0140): blocos A e B, 4 andares, 2 por andar = 16 apartamentos.
        $batch = new UnitBatch($this->pdo, $this->scope);
        $batch->create($this->user, ['condominium_id' => $this->condo, 'blocks' => 'A, B', 'floor_from' => 1, 'floor_to' => 4,
            'units_per_floor' => 2, 'digits' => 2, 'type' => 'apartamento', 'private_area' => '72,00']);
        $stmt = $this->pdo->prepare('SELECT id, block, number, floor FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY block, number');
        $stmt->execute([$this->condo]);
        $changes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->units[$row['block'] . '-' . $row['number']] = (int)$row['id'];
            // 4º andar é cobertura: 84 m² e 7% de fração; os demais 72 m² e 6%. Soma exata: 12 × 6% + 4 × 7% = 100%.
            $top = (string)$row['floor'] === '4';
            $changes[] = ['id' => (int)$row['id'], 'ideal_fraction' => $top ? '0,070000' : '0,060000', 'private_area' => $top ? '84,00' : '72,00'];
        }
        if (count($this->units) !== 16) {
            throw new DomainException('Cadastro rápido não criou as 16 unidades: ' . implode(', ', array_keys($this->units)));
        }
        $batch->saveMeasures($this->user, $this->condo, $changes);
        $this->count('unidades', 16);

        $first = ['Helena', 'Otávio', 'Beatriz', 'Caio', 'Lívia', 'Murilo', 'Sofia', 'Vicente', 'Isadora', 'Joaquim', 'Clara', 'Henrique', 'Valentina', 'Benício', 'Manuela', 'Davi', 'Alice', 'Bernardo', 'Laura', 'Gael'];
        $last = ['Prado', 'Almeida', 'Rocha', 'Vasconcelos', 'Moraes', 'Siqueira', 'Barros', 'Nogueira', 'Toledo', 'Freitas'];
        $n = 0;
        $person = static function (int $i) use ($first, $last): string {
            return $first[$i % count($first)] . ' ' . $last[($i * 3) % count($last)];
        };
        foreach ($this->units as $label => $unit) {
            $n++;
            // A-101 e A-102 têm o mesmo dono (proprietário com duas unidades).
            $ownerIndex = $label === 'A-102' ? 1 : $n;
            $this->save('residents', ['condominium_id' => $this->condo, 'unit_id' => $unit, 'relationship' => 'owner', 'name' => $person($ownerIndex),
                'document' => LabSampleData::cpf((string)(310000000 + $ownerIndex * 7)), 'email' => 'proprietario' . $ownerIndex . '@lafayette.example.com',
                'phone' => sprintf('(16) 99%03d-%04d', 100 + $ownerIndex, 2000 + $ownerIndex), 'starts_on' => '2019-03-01', 'billing_by_email' => '1']);
            if (in_array($label, ['A-202', 'B-101', 'B-201', 'B-401'], true)) {
                $this->save('residents', ['condominium_id' => $this->condo, 'unit_id' => $unit, 'relationship' => 'tenant', 'name' => $person(40 + $n),
                    'document' => LabSampleData::cpf((string)(420000000 + $n * 11)), 'email' => 'inquilino' . $n . '@lafayette.example.com',
                    'phone' => sprintf('(16) 98%03d-%04d', 200 + $n, 3000 + $n), 'starts_on' => '2025-02-01']);
            }
        }
        $u = $this->units;
        $this->save('board', ['condominium_id' => $this->condo, 'role' => 'sindico', 'name' => $person(3), 'document' => LabSampleData::cpf('310000021'),
            'unit_id' => $u['A-301'], 'term_starts_on' => '2026-01-01', 'term_ends_on' => '2027-12-31', 'email' => 'sindico@lafayette.example.com', 'phone' => '(16) 99100-4001']);
        foreach ([['subsindico', 6, 'B-102'], ['consultivo', 8, 'A-401'], ['consultivo', 10, 'B-202'], ['fiscal', 12, 'B-402']] as [$role, $i, $unitLabel]) {
            $this->board($role, $person($i), $u[$unitLabel], $i);
        }
    }

    private function board(string $role, string $name, int $unit, int $i): void
    {
        $this->save('board', ['condominium_id' => $this->condo, 'role' => $role, 'name' => $name, 'unit_id' => $unit, 'term_starts_on' => '2026-01-01',
            'term_ends_on' => '2027-12-31', 'email' => 'conselho' . $i . '@lafayette.example.com', 'phone' => '(16) 99100-40' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
    }

    private function registers(): void
    {
        $u = $this->units;
        foreach ([['zelador', 'Raimundo Teixeira', '2.950,00', 'comercial'], ['porteiro', 'Edson Ribeiro', '2.400,00', '12x36'], ['porteiro', 'Wagner Lopes', '2.400,00', '12x36'],
            ['faxineiro', 'Cleide Santos', '2.050,00', 'comercial']] as $i => [$job, $name, $salary, $shift]) {
            $this->employee($job, $name, $salary, $shift, $i);
        }
        foreach ([
            'agua' => ['Saneamento Ribeirão S.A.', 'concessionaria'], 'energia' => ['Energia Paulista Distribuidora', 'concessionaria'],
            'elevador' => ['Elevadores Altura Ltda', 'elevadores'], 'limpeza' => ['Brilho Serviços de Limpeza', 'limpeza'],
            'jardim' => ['Jardins do Vale Paisagismo', 'jardinagem'], 'bomba' => ['Hidro Bombas Manutenção', 'manutencao'],
            'seguro' => ['Proteção Total Seguros', 'outros'],
        ] as $key => [$name, $category]) {
            $this->suppliers[$key] = $this->supplier($name, $category, count($this->suppliers));
        }
        $lawyer = $this->pdo->prepare('SELECT id FROM erp_lawyers WHERE oab LIKE ? AND deleted_at IS NULL');
        $lawyer->execute(['%245810%']);
        if ($lawyer->fetchColumn() === false) {
            $this->save('lawyers', ['name' => 'Marta Cordeiro Lins', 'oab' => 'SP245810', 'document' => LabSampleData::cpf('530112874'), 'firm' => 'Cordeiro Lins Advocacia',
                'email' => 'marta@cordeirolins.example.com', 'fee_percent' => '10']);
        }
        $this->save('contacts', ['condominium_id' => $this->condo, 'service' => 'eletricista', 'name' => 'Lafayette Elétrica 24h', 'phone' => '(16) 99400-7100']);
        foreach ([['A-101', 'FLB1A23', 'carro', 'Sedã médio', 'Preto', 'G-01'], ['A-102', 'FLB2B34', 'carro', 'Hatch', 'Branco', 'G-02'], ['A-202', 'GHT3C45', 'moto', 'Moto 160cc', 'Vermelha', 'M-01'],
            ['A-301', 'RTY4D56', 'carro', 'SUV compacto', 'Cinza', 'G-03'], ['A-401', 'QWE5E67', 'carro', 'SUV', 'Prata', 'G-04'], ['B-101', 'ZXC6F78', 'carro', 'Hatch', 'Azul', 'G-05'],
            ['B-202', 'PLM7G89', 'carro', 'Picape', 'Branca', 'G-06'], ['B-302', 'MNB8H90', 'carro', 'Sedã', 'Prata', 'G-07'], ['B-401', 'KJH9J01', 'carro', 'Elétrico', 'Verde', 'G-08'],
            ['B-402', 'LKJ0K12', 'outro', 'Bicicleta aro 29', 'Preta', '']] as [$unit, $plate, $kind, $model, $color, $spot]) {
            $this->vehicle($u[$unit], $plate, $kind, $model, $color, $spot);
            if ($spot !== '') {
                $this->save('linkable_areas', ['condominium_id' => $this->condo, 'kind' => 'vaga', 'identifier' => $spot, 'unit_id' => $u[$unit], 'location' => 'Subsolo']);
            }
        }
        foreach ([['A-101', 'Rosa Prado', '1'], ['B-201', 'Diarista Fátima', '1'], ['A-302', 'Personal Jorge', '0']] as [$unit, $name, $permanent]) {
            $this->save('visitors', ['condominium_id' => $this->condo, 'unit_id' => $u[$unit], 'name' => $name, 'phone' => '(16) 99300-' . (5100 + count($this->created)), 'permanent_access' => $permanent,
                'valid_until' => $permanent === '1' ? null : (new \DateTimeImmutable($this->today))->modify('+60 days')->format('Y-m-d')]);
        }
        foreach ([['A-102', 'Pipoca', 'cao', 'pequeno'], ['B-201', 'Frajola', 'gato', 'pequeno'], ['B-401', 'Thor', 'cao', 'grande']] as [$unit, $name, $species, $size]) {
            $this->save('pets', ['condominium_id' => $this->condo, 'unit_id' => $u[$unit], 'name' => $name, 'species' => $species, 'size' => $size, 'breed' => 'SRD', 'vaccinated' => '1']);
        }
        $this->save('common_areas', ['condominium_id' => $this->condo, 'name' => 'Salão de festas', 'booking_mode' => 'approval', 'capacity' => '60', 'booking_fee_cents' => '180,00',
            'min_advance_days' => '3', 'max_advance_days' => '90', 'opening_time' => '10:00', 'closing_time' => '23:00', 'rules' => 'Uso até 23h. Limpeza por conta do morador.']);
        $this->save('common_areas', ['condominium_id' => $this->condo, 'name' => 'Churrasqueira', 'booking_mode' => 'free', 'capacity' => '25', 'opening_time' => '10:00', 'closing_time' => '22:00']);
        $this->save('linkable_areas', ['condominium_id' => $this->condo, 'kind' => 'deposito', 'identifier' => 'D-03', 'unit_id' => $u['A-301'], 'location' => 'Subsolo, ao lado do elevador']);
        foreach ([['Gerador a diesel 40 kVA', 'PAT-L001', 'equipamento', '38.500,00'], ['Bomba de recalque 3 cv', 'PAT-L002', 'equipamento', '4.200,00'],
            ['Mobília do salão de festas', 'PAT-L003', 'mobiliario', '9.800,00']] as [$description, $number, $category, $value]) {
            $this->asset($description, $number, $category, $value);
        }
        $this->save('gatehouses', ['condominium_id' => $this->condo, 'name' => 'Portaria principal', 'kind' => 'presencial']);
    }

    // ------------------------------------------------------------------ financeiro

    private function chartAndBanks(string $firstMonth): void
    {
        $opened = $firstMonth . '-01';
        // Plano de contas: reaproveita a conta do plano padrão com o mesmo código e natureza; o que faltar
        // entra no plano próprio do Lafayette (não mexe no padrão dos outros condomínios).
        foreach ([
            // chave => [código, natureza, tipo, nome]. Os códigos seguem o plano padrão da Connect (migrations 0704 e 0709);
            // água e acordos não existem no padrão e ficam no plano próprio do Lafayette (1.91 e 1.92).
            'receitas' => ['1', 'receita', 'sintetica', 'Receitas'], 'taxa' => ['1.01', 'receita', 'analitica', 'Taxa condominial'],
            'fundo' => ['1.02', 'receita', 'analitica', 'Fundo de reserva'], 'multas' => ['1.03', 'receita', 'analitica', 'Multas e juros'],
            'extras' => ['1.04', 'receita', 'analitica', 'Rateios e cotas extras'], 'rendimentos' => ['1.05', 'receita', 'analitica', 'Rendimentos de aplicação'],
            'reservas' => ['1.06', 'receita', 'analitica', 'Locação de áreas comuns'], 'agua_receita' => ['1.91', 'receita', 'analitica', 'Consumo de água'],
            'acordos' => ['1.92', 'receita', 'analitica', 'Acordos'],
            'despesas' => ['2', 'despesa', 'sintetica', 'Despesas'], 'pessoal' => ['2.01', 'despesa', 'analitica', 'Pessoal e encargos'],
            'agua' => ['2.02', 'despesa', 'analitica', 'Água e esgoto'], 'energia' => ['2.03', 'despesa', 'analitica', 'Energia elétrica'],
            'manutencao' => ['2.04', 'despesa', 'sintetica', 'Manutenção e conservação'], 'bomba' => ['2.04.02', 'despesa', 'analitica', 'Manutenção hidráulica'],
            'elevadores' => ['2.04.03', 'despesa', 'analitica', 'Elevadores'], 'jardinagem' => ['2.04.04', 'despesa', 'analitica', 'Jardinagem'],
            'limpeza' => ['2.05', 'despesa', 'analitica', 'Limpeza e material de consumo'], 'seguro' => ['2.06', 'despesa', 'analitica', 'Seguros'],
            'adm' => ['2.07', 'despesa', 'analitica', 'Administração e honorários'], 'tarifas' => ['2.08', 'despesa', 'analitica', 'Tarifas bancárias'],
            'impostos' => ['2.09', 'despesa', 'analitica', 'Impostos e taxas'],
            '7' => ['7', 'asset', 'sintetica', 'Ativo'], '7.1' => ['7.1', 'asset', 'sintetica', 'Disponível'], '7.2' => ['7.2', 'asset', 'analitica', 'Contas a receber'],
            '8' => ['8', 'liability', 'sintetica', 'Passivo'], '8.1' => ['8.1', 'liability', 'analitica', 'Contas a pagar'], '8.2' => ['8.2', 'liability', 'analitica', 'Fundo de reserva'],
            '9' => ['9', 'equity', 'sintetica', 'Patrimônio Líquido'], '9.1' => ['9.1', 'equity', 'analitica', 'Resultados acumulados'], '9.2' => ['9.2', 'equity', 'analitica', 'Resultado do exercício'],
        ] as $key => [$code, $nature, $kind, $name]) {
            $this->accounts[$key] = $this->account($code, $nature, $kind, $name);
        }
        $center = $this->save('cost_centers', ['condominium_id' => $this->condo, 'code' => 'LAF-01', 'name' => 'Lafayette ordinário']);
        $this->banks['movimento'] = $this->save('bank_accounts', ['condominium_id' => $this->condo, 'name' => 'Conta movimento', 'bank_code' => '001', 'branch' => '4410',
            'account_number' => '77810-2', 'kind' => 'corrente', 'wallet' => '17', 'opening_balance_cents' => '25.000,00', 'opened_on' => $opened, 'is_main' => '1', 'cost_center_id' => $center]);
        $this->banks['reserva'] = $this->save('bank_accounts', ['condominium_id' => $this->condo, 'name' => 'Poupança do fundo de reserva', 'bank_code' => '001', 'branch' => '4410',
            'account_number' => '91234-0', 'kind' => 'poupanca', 'opening_balance_cents' => '40.000,00', 'opened_on' => $opened]);
        $this->banks['aplicacao'] = $this->save('bank_accounts', ['condominium_id' => $this->condo, 'name' => 'CDB liquidez diária', 'bank_code' => '001', 'branch' => '4410',
            'account_number' => '55001-7', 'kind' => 'aplicacao', 'parent_bank_account_id' => $this->banks['movimento'], 'opening_balance_cents' => '0,00', 'opened_on' => $opened]);
        // Abertura do balanço (AP-161): bancos 65.000 = fundo de reserva 40.000 + resultados acumulados 25.000.
        $openings = new ChartOpenings($this->pdo, $this->scope);
        $openings->set($this->user, $this->condo, $this->accounts['8.2'], $opened, '40.000,00', 'Saldo do fundo na implantação');
        $openings->set($this->user, $this->condo, $this->accounts['9.1'], $opened, '25.000,00', 'Saldo do caixa na implantação');
        $this->save('invoice_types', ['condominium_id' => $this->condo, 'name' => 'Taxa condominial Lafayette', 'chart_account_id' => $this->accounts['taxa'], 'recurrence' => 'mensal']);
        $this->save('invoice_types', ['condominium_id' => $this->condo, 'name' => 'Fundo de reserva Lafayette', 'chart_account_id' => $this->accounts['fundo'], 'recurrence' => 'mensal']);
        // Lançamentos fixos (AP-021): limpeza terceirizada e honorários da administradora todo mês.
        $this->save('recurring_entries', ['condominium_id' => $this->condo, 'nature' => 'despesa', 'description' => 'Limpeza terceirizada (contrato)', 'amount_cents' => '3.200,00',
            'chart_account_id' => $this->accounts['limpeza'], 'supplier_id' => $this->suppliers['limpeza'], 'bank_account_id' => $this->banks['movimento'], 'day_of_month' => '15', 'starts_competence' => $firstMonth]);
        $this->save('recurring_entries', ['condominium_id' => $this->condo, 'nature' => 'despesa', 'description' => 'Honorários da administradora', 'amount_cents' => '1.500,00',
            'chart_account_id' => $this->accounts['adm'], 'bank_account_id' => $this->banks['movimento'], 'day_of_month' => '5', 'starts_competence' => $firstMonth]);
        (new BillingRules($this->pdo, $this->scope))->save($this->user, $this->condo, ['fine_bp' => 200, 'monthly_interest_bp' => 100, 'price_index' => 'none',
            'breach_days_late' => 30, 'breach_open_installments' => 2]);
        $this->save('contracts', ['condominium_id' => $this->condo, 'supplier_id' => $this->suppliers['limpeza'], 'object' => 'Limpeza das áreas comuns',
            'starts_on' => $opened, 'ends_on' => (new \DateTimeImmutable($opened))->modify('+1 year -1 day')->format('Y-m-d'), 'monthly_cents' => '3.200,00', 'readjustment' => 'ipca']);
        $this->save('insurances', ['condominium_id' => $this->condo, 'insurer' => 'Proteção Total Seguros', 'policy_number' => 'LAF-2026-001', 'starts_on' => $opened,
            'ends_on' => (new \DateTimeImmutable($opened))->modify('+1 year -1 day')->format('Y-m-d'), 'premium_cents' => '4.800,00', 'coverage_cents' => '8.000.000,00']);
    }

    /** Relógios de água por unidade, tarifa progressiva do condomínio e leitura todo mês. */
    private function water(array $months): void
    {
        $opened = $months[0] . '-01';
        foreach ([['Faixa 1', '0', '10', '7,20'], ['Faixa 2', '10,001', '20', '9,80'], ['Faixa 3', '20,001', '', '13,50']] as [$name, $from, $to, $price]) {
            $this->save('tariffs', ['condominium_id' => $this->condo, 'kind' => 'agua', 'name' => $name, 'from_amount' => $from, 'to_amount' => $to, 'price_cents' => $price, 'valid_from' => $opened]);
        }
        $readings = new MeterReadings($this->pdo, $this->scope);
        $i = 0;
        foreach ($this->units as $label => $unit) {
            $i++;
            $meter = $this->save('meters', ['condominium_id' => $this->condo, 'unit_id' => $unit, 'kind' => 'agua', 'number' => 'HD-' . (40100 + $i), 'installed_on' => '2024-01-15', 'initial_reading' => (string)(300 + $i * 17)]);
            $value = 300 + $i * 17;
            foreach ($months as $m => $competence) {
                // Consumo entre 6 e 27 m³, variando por unidade e mês (determinístico).
                $value += 6 + (($i * 7 + $m * 5) % 22);
                $readOn = $this->monthDate($competence, 1, 25);
                $readings->record($this->user, ['meter_id' => $meter, 'competence' => $competence, 'read_on' => $readOn, 'reading' => (string)$value]);
                $this->count('leituras');
            }
        }
    }

    /** Um mês: faturas, água, boletos, retorno do banco, despesas, transferência, aplicação. */
    private function month(string $competence, int $index, int $total): void
    {
        $last = $index === $total - 1;
        $due = $this->monthDate($competence, 0, 10);
        $invoices = new Invoices($this->pdo, $this->scope);
        $types = $this->pdo->prepare('SELECT id FROM erp_invoice_types WHERE condominium_id = ? AND name = ?');
        $receivables = [];
        foreach ([['Taxa condominial Lafayette', self::FEE_TOTAL_CENTS], ['Fundo de reserva Lafayette', self::RESERVE_TOTAL_CENTS]] as [$name, $cents]) {
            $types->execute([$this->condo, $name]);
            $draft = $invoices->prepare($this->user, ['condominium_id' => $this->condo, 'invoice_type_id' => (int)$types->fetchColumn(), 'competence' => $competence,
                'due_on' => $due, 'method' => 'fraction', 'amount_cents' => number_format($cents / 100, 2, ',', '')]);
            foreach ($invoices->issue($this->user, (int)$draft['id'])['items'] as $item) {
                $receivables[] = (int)$item['entry_id'];
            }
            $this->count('faturas');
        }
        $water = (new MeterReadings($this->pdo, $this->scope))->bill($this->user, ['condominium_id' => $this->condo, 'competence' => $competence, 'kind' => 'agua',
            'due_on' => $due, 'chart_account_id' => $this->accounts['agua_receita']]);
        $receivables = array_merge($receivables, $water['created']);
        if ($index === 3) {
            // Troca da bomba de recalque: despesa paga agora; o rateio entre as unidades é cobrado no mês corrente (operations()).
            $this->payable($competence, 'Troca da bomba de recalque', 4_800_00, 'bomba', 'bomba', 18, 'NF 8812');
        }

        // Boletos (sandbox) e retorno: todos pagam no vencimento, menos os casos de inadimplência e o Pix.
        $charges = new BankCharges($this->pdo, $this->scope);
        $unitOf = $this->pdo->prepare('SELECT CONCAT(u.block, \'-\', u.number) FROM erp_entries e JOIN erp_units u ON u.id = e.unit_id WHERE e.id = ?');
        $events = [];
        foreach ($receivables as $entryId) {
            $unitOf->execute([$entryId]);
            $label = (string)$unitOf->fetchColumn();
            $agreementMonth = $label === self::AGREEMENT_UNIT && $index >= 1 && $index <= 3;
            $lateMonth = $label === self::LATE_UNIT && $index >= $total - 2;
            $pixMonth = $label === self::PIX_UNIT && $index === 2;
            if ($agreementMonth || $pixMonth) {
                continue;   // sem boleto: vai para acordo ou é paga por Pix
            }
            $charge = $charges->issue($this->user, $entryId, $this->banks['movimento']);
            $this->count('boletos');
            if (!$lateMonth) {
                $events[] = ['event_key' => 'LAF-' . $competence . '-' . $entryId, 'kind' => 'paid', 'our_number' => $charge['our_number'],
                    'amount_cents' => $charge['amount_cents'], 'occurred_on' => $this->monthDate($competence, 0, 8 + $entryId % 3)];
            }
        }
        if ($events !== []) {
            $result = (new BankReturns($this->pdo, $this->scope))->process($this->user, $this->banks['movimento'], 'lafayette-retorno-' . $competence . '.ret', SandboxBankAdapter::buildReturn($events));
            $this->count('retornos');
            foreach ($result['events'] as $event) {
                if (($event['outcome'] ?? '') !== 'applied') {
                    throw new DomainException('Retorno de ' . $competence . ' não baixou um título: ' . json_encode($event, JSON_UNESCAPED_UNICODE));
                }
            }
        }

        // Despesas do mês (pagas no mês seguinte; as do último mês ficam a pagar).
        $pay = !$last;
        $energy = 1_380_00 + ($index * 7_311) % 25_000;
        $this->payable($competence, 'Folha de pagamento', 6_800_00, 'pessoal', null, 5, null, $pay);
        $this->payable($competence, 'INSS e FGTS', 1_904_00, 'pessoal', null, 20, null, $pay);
        $this->payable($competence, 'Conta de água e esgoto (concessionária)', $this->waterBill($competence), 'agua', 'agua', 22, 'FAT-' . str_replace('-', '', $competence), $pay);
        $this->payable($competence, 'Energia elétrica das áreas comuns', $energy, 'energia', 'energia', 25, 'NF ' . (5100 + $index), $pay);
        $this->payable($competence, 'Manutenção dos elevadores', 980_00, 'elevadores', 'elevador', 12, 'NF ' . (9300 + $index), $pay);
        $this->payable($competence, 'Jardinagem', 650_00, 'jardinagem', 'jardim', 15, 'RPA ' . (77 + $index), $pay);
        $this->payable($competence, 'Tarifas bancárias', 45_90, 'tarifas', null, 28, null, true);
        if ($index === 0) {
            $this->payable($competence, 'Seguro predial (apólice anual)', 4_800_00, 'seguro', 'seguro', 20, 'APL LAF-2026-001');
        }
        $generated = (new RecurringEntries($this->pdo, $this->scope))->generate($this->user, $competence, $this->condo);
        foreach ($generated['created'] ?? [] as $entry) {
            $id = (int)(is_array($entry) ? ($entry['id'] ?? $entry['entry_id']) : $entry);
            if ($pay) {
                $this->payOff($id, $this->monthDate($competence, 1, 5));
            }
            $this->count('lancamentos_fixos');
        }

        $shortcuts = new EntryShortcuts($this->pdo, $this->scope);
        $shortcuts->transfer($this->user, ['condominium_id' => $this->condo, 'from_bank_account_id' => $this->banks['movimento'], 'to_bank_account_id' => $this->banks['reserva'],
            'amount_cents' => number_format(self::RESERVE_TOTAL_CENTS / 100, 2, ',', '.'), 'transferred_on' => $this->monthDate($competence, 0, 15), 'description' => 'Fundo de reserva do mês para a poupança']);
        $this->count('transferencias');
        $investments = new Investments($this->pdo, $this->scope, $shortcuts);
        if ($index === 0) {
            $investments->apply($this->user, $this->banks['aplicacao'], ['amount_cents' => '15.000,00', 'transferred_on' => $this->monthDate($competence, 0, 16)]);
            $this->count('aplicacoes');
        }
        if ($index === $total - 2) {
            $investments->redeem($this->user, $this->banks['aplicacao'], ['amount_cents' => '5.000,00', 'transferred_on' => $this->monthDate($competence, 0, 16)]);
            $this->count('resgates');
        }
        // Rendimento (receita) e IR (despesa) no último dia do mês, na conta da aplicação.
        $income = 120_00 + $index * 3_17;
        $end = $this->monthDate($competence, 0, 31);
        $this->entry('receita', $competence, 'Rendimento do CDB', $income, 'rendimentos', $end, $end, $this->banks['aplicacao']);
        $this->entry('despesa', $competence, 'IR sobre rendimento do CDB', intdiv($income * 15, 100), 'impostos', $end, $end, $this->banks['aplicacao']);
    }

    /** Água da concessionária = consumo das unidades no mês + 12% de área comum, a R$ 9,10 o m³. */
    private function waterBill(string $competence): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(consumption), 0) FROM erp_meter_readings WHERE condominium_id = ? AND competence = ?');
        $stmt->execute([$this->condo, $competence]);
        return (int)round((float)$stmt->fetchColumn() * 1.12 * 910);
    }

    private function delinquency(array $months): void
    {
        $u = $this->units;
        // Pix da A-102 no mês 3: pago 18 dias depois do vencimento, com multa avulsa de 2% + juros.
        $pixMonth = $months[2];
        $stmt = $this->pdo->prepare("SELECT id, amount_cents FROM erp_entries WHERE unit_id = ? AND competence = ? AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute([$u[self::PIX_UNIT], $pixMonth]);
        $principal = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $principal += (int)$row['amount_cents'];
            $this->payOff((int)$row['id'], $this->monthDate($pixMonth, 0, 28), 'PIX-E2E-' . $row['id']);
        }
        $fine = (int)round($principal * 0.02) + (int)round($principal * 0.01 * 18 / 30);
        // Multa e juros cobrados junto com o Pix: receita de multas, paga no mesmo dia.
        $this->entry('receita', $pixMonth, 'Multa e juros por atraso — ' . self::PIX_UNIT, $fine, 'multas', $this->monthDate($pixMonth, 0, 28),
            $this->monthDate($pixMonth, 0, 28), $this->banks['movimento'], $u[self::PIX_UNIT], null, 'PIX-MULTA');
        $this->count('multas');

        // B-302: três meses sem pagar viram acordo em 4 parcelas, com encargos.
        $stmt = $this->pdo->prepare("SELECT id FROM erp_entries WHERE unit_id = ? AND nature = 'receita' AND status = 'active' AND paid_on IS NULL AND deleted_at IS NULL AND due_on < ? ORDER BY due_on, id");
        $stmt->execute([$u[self::AGREEMENT_UNIT], $this->today]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $firstDue = (new \DateTimeImmutable($this->today))->modify('first day of next month')->modify('+9 days')->format('Y-m-d');
            (new Agreements($this->pdo, $this->scope))->create($this->user, ['unit_id' => $u[self::AGREEMENT_UNIT], 'entry_ids' => $ids, 'installments' => 4,
                'first_due_on' => $firstDue, 'chart_account_id' => $this->accounts['acordos'], 'fees_cents' => '150,00', 'notes' => 'Acordo do Lafayette (dados de teste)']);
            $this->count('acordos');
        }
        // A-201: dois meses em aberto, contato de cobrança com promessa.
        (new Collections($this->pdo, $this->scope))->recordContact($this->user, ['unit_id' => $u[self::LATE_UNIT], 'channel' => 'whatsapp', 'outcome' => 'promise',
            'note' => 'Moradora informou que paga as duas taxas até o fim do mês.', 'contact_name' => 'Proprietária',
            'promise_on' => (new \DateTimeImmutable($this->today))->modify('+10 days')->format('Y-m-d'), 'promise_cents' => '2.000,00']);
        $this->count('contatos_cobranca');
    }

    private function operations(array $months): void
    {
        $u = $this->units;
        $areas = $this->pdo->prepare('SELECT id FROM erp_common_areas WHERE condominium_id = ? AND name = ?');
        $areas->execute([$this->condo, 'Salão de festas']);
        $party = (int)$areas->fetchColumn();
        $areas->execute([$this->condo, 'Churrasqueira']);
        $grill = (int)$areas->fetchColumn();
        $future = fn (int $days): string => (new \DateTimeImmutable($this->today))->modify("+{$days} days")->format('Y-m-d');
        $past = fn (int $days): string => (new \DateTimeImmutable($this->today))->modify("-{$days} days")->format('Y-m-d');
        $this->save('reservations', ['condominium_id' => $this->condo, 'common_area_id' => $party, 'unit_id' => $u['A-401'], 'resident_name' => 'Morador da A-401',
            'reserved_on' => $future(9), 'starts_time' => '18:00', 'ends_time' => '23:00', 'fee_cents' => '180,00']);
        $this->save('reservations', ['condominium_id' => $this->condo, 'common_area_id' => $grill, 'unit_id' => $u['B-102'], 'resident_name' => 'Morador da B-102',
            'reserved_on' => $future(4), 'starts_time' => '12:00', 'ends_time' => '17:00']);
        // Taxa de reserva do salão já usada no mês passado: receita paga.
        $lastMonth = end($months);
        $this->entry('receita', $lastMonth, 'Reserva do salão de festas — B-201', 180_00, 'reservas', $this->monthDate($lastMonth, 0, 12), $this->monthDate($lastMonth, 0, 12), $this->banks['movimento'], $u['B-201']);
        $noise = $this->save('occurrences', ['condominium_id' => $this->condo, 'unit_id' => $u['B-301'], 'subject' => 'Som alto após as 22h', 'priority' => 'media', 'occurred_on' => $past(12),
            'description' => 'Reclamação de barulho na B-301 em dia de semana, depois das 22h.']);
        $this->save('occurrences', ['condominium_id' => $this->condo, 'subject' => 'Infiltração na garagem', 'priority' => 'alta', 'occurred_on' => $past(5),
            'description' => 'Mancha de umidade no teto da vaga G-05, abaixo do jardim.']);
        // Cobranças do mês corrente (vencimento futuro, como exige a tela): rateio extra da bomba e multa da ocorrência.
        $current = (new \DateTimeImmutable($this->today))->format('Y-m');
        $shortcuts = new EntryShortcuts($this->pdo, $this->scope);
        $shortcuts->createCharge($this->user, ['condominium_id' => $this->condo, 'kind' => 'extra', 'method' => 'fraction', 'unit_ids' => array_values($u), 'description' => 'Rateio da troca da bomba de recalque',
            'amount_cents' => '4.800,00', 'competence' => $current, 'due_on' => $future(15), 'chart_account_id' => $this->accounts['extras']]);
        $this->count('cobrancas_extras');
        $shortcuts->createCharge($this->user, ['condominium_id' => $this->condo, 'kind' => 'fine', 'unit_ids' => [$u['B-301']], 'description' => 'Multa por barulho após as 22h',
            'amount_cents' => '250,00', 'competence' => $current, 'due_on' => $future(15), 'chart_account_id' => $this->accounts['multas'],
            'reason' => 'Regulamento interno, art. 8 (silêncio após as 22h)', 'occurrence_id' => $noise]);
        $this->count('multas');
        $this->save('moves', ['condominium_id' => $this->condo, 'unit_id' => $u['B-401'], 'resident_name' => 'Inquilino da B-401', 'kind' => 'entrada', 'scheduled_on' => $future(6), 'period' => 'manha']);
        $this->save('maintenances', ['condominium_id' => $this->condo, 'service' => 'Manutenção preventiva dos elevadores', 'supplier_id' => $this->suppliers['elevador'],
            'planned_on' => $future(7), 'frequency' => 'mensal', 'description' => 'Visita mensal obrigatória.']);
        $this->save('maintenances', ['condominium_id' => $this->condo, 'service' => 'Limpeza da caixa d’água', 'supplier_id' => $this->suppliers['bomba'],
            'planned_on' => $future(20), 'frequency' => 'semestral', 'description' => 'Limpeza e laudo de potabilidade.']);
        $this->save('deliveries', ['condominium_id' => $this->condo, 'unit_id' => $u['A-302'], 'recipient' => 'Morador da A-302', 'carrier' => 'correios', 'tracking_code' => 'LB123456789BR', 'received_on' => $past(1)]);
        $this->save('lost_found', ['condominium_id' => $this->condo, 'item' => 'Molho de chaves com chaveiro azul', 'found_on' => $past(3), 'location' => 'Salão de festas']);
        $this->save('visit_forecasts', ['condominium_id' => $this->condo, 'unit_id' => $u['A-202'], 'visitor_name' => 'Técnico da internet', 'expected_on' => $future(2)]);
        // Comprovante de quitação de uma unidade em dia e certidão de débitos da unidade em atraso.
        $documents = new UnitDocuments($this->pdo, $this->scope);
        $documents->issue($this->user, ['kind' => 'quittance', 'unit_id' => $u['A-301'], 'as_of' => $this->today, 'until_competence' => end($months)]);
        $documents->issue($this->user, ['kind' => 'debt_certificate', 'unit_id' => $u[self::LATE_UNIT], 'as_of' => $this->today]);
        $this->count('documentos_unidade', 2);
        (new Announcements($this->pdo, $this->scope))->announce($this->user, ['condominium_id' => $this->condo, 'subject' => 'Limpeza da caixa d’água',
            'body' => 'Informamos que a limpeza da caixa d’água será feita no próximo mês. O fornecimento será interrompido das 8h às 14h.', 'audience' => 'all']);
        $this->count('comunicados');
        (new TicketService($this->pdo))->open(['subject' => 'Segunda via do boleto de outubro', 'message' => 'Não encontrei o boleto de outubro no e-mail. Podem reenviar?',
            'condominium_id' => $this->condo], 'erp', $this->user);
        $this->count('chamados');
    }

    private function assembly(array $months): void
    {
        $assemblies = new Assemblies($this->pdo, $this->scope);
        $at = $this->monthDate($months[count($months) - 2], 0, 20) . ' 19:30';
        $created = $assemblies->create($this->user, ['condominium_id' => $this->condo, 'kind' => 'ordinaria', 'mode' => 'presencial', 'title' => 'Assembleia geral ordinária do Lafayette',
            'location' => 'Salão de festas', 'first_call_at' => $at, 'second_call_at' => substr($at, 0, 10) . ' 20:00', 'items' => [
                ['title' => 'Aprovação das contas do semestre', 'vote_basis' => 'fraction', 'quorum' => 'simple'],
                ['title' => 'Previsão orçamentária do próximo ano', 'vote_basis' => 'fraction', 'quorum' => 'simple'],
            ]]);
        $id = (int)$created['id'];
        $start = new \DateTimeImmutable($at);
        $assemblies->convene($this->user, $id, $start->modify('-15 days'));
        $assemblies->open($this->user, $id, $start);
        $voters = ['A-101', 'A-301', 'A-401', 'B-102', 'B-202', 'B-402', 'A-302', 'B-301'];
        foreach ($voters as $label) {
            $assemblies->checkIn($this->user, $id, ['unit_id' => $this->units[$label], 'attendee_name' => 'Proprietário da ' . $label], $start->modify('+5 minutes'));
        }
        $items = $assemblies->find($this->user, $id)['items'] ?? [];
        foreach ($items as $n => $item) {
            $assemblies->startVoting($this->user, $id, (int)$item['id']);
            foreach ($voters as $v => $label) {
                $assemblies->vote($this->user, $id, (int)$item['id'], $this->units[$label], $v === 7 && $n === 0 ? 'abstain' : ($v === 6 ? 'no' : 'yes'));
            }
            $assemblies->closeItem($this->user, $id, (int)$item['id']);
        }
        $assemblies->close($this->user, $id, $start->modify('+2 hours'));
        $this->count('assembleias');
    }

    /**
     * Apaga o Lafayette inteiro para criar de novo: as linhas de todas as tabelas com condominium_id deste
     * condomínio e as filhas delas (itens de fatura, votos, parcelas de acordo...), numa transação. Fornecedores
     * e a advogada são cadastros da administradora (sem condomínio) e ficam; a trilha de auditoria também fica.
     *
     * @return array<string,int> linhas apagadas por tabela
     */
    public function remove(): array
    {
        $condo = $this->condominiumId();
        if ($condo === null) {
            return [];
        }
        $schema = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $columns = $this->pdo->prepare("SELECT table_name FROM information_schema.columns WHERE table_schema = ? AND column_name = 'condominium_id'
            AND table_name IN (SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE')");
        $columns->execute([$schema, $schema]);
        $scoped = $columns->fetchAll(PDO::FETCH_COLUMN);
        $children = $this->pdo->prepare('SELECT k.table_name, k.column_name, k.referenced_table_name FROM information_schema.key_column_usage k
            WHERE k.table_schema = ? AND k.referenced_table_name IS NOT NULL ORDER BY k.table_name');
        $children->execute([$schema]);
        $quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
        $removed = [];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($children->fetchAll(PDO::FETCH_NUM) as [$table, $column, $parent]) {
                if (in_array($table, $scoped, true) || !in_array($parent, $scoped, true) || $parent === 'condominiums') {
                    continue;
                }
                $stmt = $this->pdo->prepare('DELETE FROM ' . $quote($table) . ' WHERE ' . $quote($column) . ' IN (SELECT id FROM ' . $quote($parent) . ' WHERE condominium_id = ?)');
                $stmt->execute([$condo]);
                $removed[$table] = ($removed[$table] ?? 0) + $stmt->rowCount();
            }
            foreach ($scoped as $table) {
                $stmt = $this->pdo->prepare('DELETE FROM ' . $quote($table) . ' WHERE condominium_id = ?');
                $stmt->execute([$condo]);
                $removed[$table] = $stmt->rowCount();
            }
            $this->pdo->prepare('DELETE FROM condominiums WHERE id = ?')->execute([$condo]);
            $removed['condominiums'] = 1;
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            throw $error;
        }
        Audit::record('delete', 'condominiums', $condo, ['name' => self::NAME], ['removed' => array_sum($removed), 'reason' => 'dados de teste do Lafayette']);
        return array_filter($removed);
    }

    // ------------------------------------------------------------------ conferência

    /**
     * Confere se os números fecham. Cada linha: o que se confere, esperado, encontrado (centavos) e ok.
     *
     * @return array{condominium_id:int, checks:list<array{check:string, expected:int, actual:int, ok:bool}>, ok:bool}
     */
    public function verify(): array
    {
        $condo = $this->condominiumId();
        if ($condo === null) {
            throw new DomainException('O condomínio Lafayette não existe. Rode a criação primeiro.');
        }
        $this->condo = $condo;
        $months = self::competences($this->today);
        $from = $months[0];
        $to = end($months);
        $fromDate = $from . '-01';
        $toDate = (new \DateTimeImmutable($to . '-01'))->modify('last day of this month')->format('Y-m-d');
        $checks = [];
        $add = static function (string $check, int $expected, int $actual) use (&$checks): void {
            $checks[] = ['check' => $check, 'expected' => $expected, 'actual' => $actual, 'ok' => $expected === $actual];
        };

        // Somas diretas no banco (referência independente dos services).
        $sum = function (string $where, array $params = []): int {
            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE condominium_id = :c AND status = 'active' AND deleted_at IS NULL AND {$where}");
            $stmt->execute(['c' => $this->condo] + $params);
            return (int)$stmt->fetchColumn();
        };
        $period = ['f' => $from, 't' => $to];

        // 1. Faturas: cada mês a taxa soma exatamente o orçamento e o fundo, 10% dele.
        foreach ($months as $competence) {
            $stmt = $this->pdo->prepare("SELECT t.name, SUM(i.amount_cents) FROM erp_invoices v JOIN erp_invoice_items i ON i.invoice_id = v.id
                JOIN erp_invoice_types t ON t.id = v.invoice_type_id WHERE v.condominium_id = ? AND v.competence = ? AND v.status <> 'cancelled' GROUP BY t.name");
            $stmt->execute([$this->condo, $competence]);
            $byType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $add("Fatura da taxa {$competence} = orçamento rateado", self::FEE_TOTAL_CENTS, (int)($byType['Taxa condominial Lafayette'] ?? 0));
            $add("Fatura do fundo {$competence} = 10% do orçamento", self::RESERVE_TOTAL_CENTS, (int)($byType['Fundo de reserva Lafayette'] ?? 0));
        }
        // 2. Água: leituras cobradas = contas a receber de água (todas as leituras com valor foram cobradas).
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(amount_cents), 0), COALESCE(SUM(entry_id IS NULL AND amount_cents > 0), 0) FROM erp_meter_readings WHERE condominium_id = ?');
        $stmt->execute([$this->condo]);
        [$readingCents, $unbilled] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
        $add('Água: soma das leituras = contas a receber de água', $readingCents,
            $this->sumAll("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e JOIN erp_meter_readings r ON r.entry_id = e.id WHERE r.condominium_id = :c"));
        $add('Água: leituras com valor e sem cobrança', 0, $unbilled);

        // 3. Boletos pagos pelo retorno = contas a receber baixadas com boleto.
        $add('Boletos pagos = contas a receber baixadas por boleto',
            $this->sumAll("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_bank_charges WHERE condominium_id = :c AND status = 'paid'"),
            $this->sumAll("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e JOIN erp_bank_charges b ON b.entry_id = e.id AND b.status = 'paid' WHERE e.condominium_id = :c AND e.paid_on IS NOT NULL"));
        $add('Divergências pendentes no retorno bancário', 0,
            $this->sumAll("SELECT COUNT(*) FROM erp_bank_events ev JOIN erp_bank_return_files f ON f.id = ev.return_file_id WHERE f.condominium_id = :c AND ev.outcome = 'divergent' AND ev.resolved_at IS NULL"));

        // 4. Saldos bancários: BankBalances = conciliação do AP-073 = conta direta no banco.
        $balances = (new BankBalances($this->pdo, $this->scope))->forUser($this->user, $this->condo);
        $byAccount = [];
        foreach ($balances as $row) {
            $byAccount[(int)$row['id']] = (int)($row['balance_cents'] ?? $row['current_cents'] ?? 0);
        }
        $reports = new AccountingReports($this->pdo, $this->scope);
        $asOf = $reports->accountBalances($this->user, $this->condo, $this->today);
        $direct = 0;
        foreach ($this->bankRows() as $bank) {
            $id = (int)$bank['id'];
            $expected = $this->directBalance($id, (int)$bank['opening_balance_cents'], $bank['opened_on']);
            $direct += $expected;
            $add("Saldo de {$bank['name']} (Contas bancárias)", $expected, $byAccount[$id] ?? -1);
            $report = null;
            foreach ($asOf['rows'] ?? $asOf['accounts'] ?? [] as $row) {
                if ((int)($row['bank_account_id'] ?? $row['id'] ?? 0) === $id) {
                    $report = (int)($row['closing_cents'] ?? $row['balance_cents'] ?? 0);
                }
            }
            $add("Saldo de {$bank['name']} (Saldos de contas AP-073)", $expected, $report ?? -1);
        }
        $add('Transferências não mudam o total (bancos = abertura + receitas pagas − despesas pagas)',
            65_000_00 + $sum("nature = 'receita' AND paid_on IS NOT NULL") - $sum("nature = 'despesa' AND paid_on IS NOT NULL"), $direct);

        // 5. Prestação de contas por caixa (AP-051): diferença da conciliação zero.
        $statement = (new FinancialReports($this->pdo, $this->scope))->statement($this->user, $this->condo, $fromDate, $this->today);
        $add('Prestação de contas: diferença da conciliação', 0, (int)($statement['reconciliation']['difference_cents'] ?? $statement['totals']['difference_cents'] ?? $this->findKey($statement, 'difference_cents')));

        // 6. Balancete (AP-079) e DRE (AP-082) contra a soma direta por competência.
        $revenue = $sum("nature = 'receita' AND competence BETWEEN :f AND :t", $period);
        $expense = $sum("nature = 'despesa' AND competence BETWEEN :f AND :t", $period);
        $trial = (new FinancialReports($this->pdo, $this->scope))->trialBalance($this->user, $this->condo, $from, $to);
        $roots = [];
        foreach ($trial['rows'] ?? [] as $row) {
            $roots[(string)$row['code']] = (int)($row['expected_cents'] ?? $row['planned_cents'] ?? $row['competence_cents'] ?? 0);
        }
        $add('Balancete: receitas do semestre (conta 1)', $revenue, $roots['1'] ?? -1);
        $add('Balancete: despesas do semestre (conta 2)', $expense, $roots['2'] ?? -1);
        $dre = $reports->incomeStatement($this->user, $this->condo, $fromDate, $toDate, 'competence');
        $add('DRE: resultado do semestre = receitas − despesas', $revenue - $expense, (int)($dre['result_cents'] ?? $dre['totals']['result_cents'] ?? $this->findKey($dre, 'result_cents')));

        // 7. Fechamento mensal: receitas e despesas de cada mês batem com a soma direta.
        $closing = new MonthClosing($this->pdo, $this->scope);
        foreach ($months as $competence) {
            $status = $closing->status($this->user, $this->condo, $competence);
            $summary = $status['summary'] ?? $status;
            $add("Fechamento {$competence}: receitas", $sum("nature = 'receita' AND competence = :m", ['m' => $competence]), (int)($summary['revenue_cents'] ?? $this->findKey($summary, 'revenue_cents')));
            $add("Fechamento {$competence}: despesas", $sum("nature = 'despesa' AND competence = :m", ['m' => $competence]), (int)($summary['expense_cents'] ?? $this->findKey($summary, 'expense_cents')));
        }

        // 8. Inadimplência (AP-057) = contas a receber vencidas e em aberto.
        $overdue = $sum("nature = 'receita' AND paid_on IS NULL AND due_on < :d", ['d' => $this->today]);
        $delinquency = (new Agreements($this->pdo, $this->scope))->delinquency($this->user, $this->condo, $this->today);
        $add('Inadimplência = contas a receber vencidas em aberto', $overdue, (int)($delinquency['totals']['amount_cents'] ?? $delinquency['total_cents'] ?? $this->findKey($delinquency, 'amount_cents')));
        $add('Unidade do acordo sem dívida vencida', 0, $sum("nature = 'receita' AND paid_on IS NULL AND due_on < :d AND unit_id = :u", ['d' => $this->today, 'u' => $this->unitId(self::AGREEMENT_UNIT)]));

        $collections = new CollectionReports($this->pdo, $this->scope);
        $delinquents = $collections->delinquents($this->user, $this->condo, $this->today, 'general');
        $add('Relatório de inadimplentes (AP-057) = contas vencidas em aberto', $overdue, (int)($delinquents['totals']['amount_cents'] ?? $this->findKey($delinquents, 'amount_cents')));
        $receipts = $collections->receipts($this->user, $this->condo, $fromDate, $this->today);
        $add('Recebimentos (AP-071) = receitas pagas no período', $sum("nature = 'receita' AND paid_on BETWEEN :a AND :b", ['a' => $fromDate, 'b' => $this->today]),
            (int)($receipts['totals']['amount_cents'] ?? -1));
        foreach ($months as $competence) {
            $package = (new Accountability($this->pdo, $this->scope))->build($this->user, $this->condo, $competence);
            $add("Prestação de contas {$competence}: resultado = receitas − despesas da competência",
                $sum("nature = 'receita' AND competence = :m", ['m' => $competence]) - $sum("nature = 'despesa' AND competence = :m", ['m' => $competence]),
                $this->findKey($package['income'] ?? $package['dre'] ?? $package, 'result_cents'));
        }

        // 9. Balanço Patrimonial (AP-081) fecha: ativo = passivo + PL.
        $balance = (new BalanceSheet($this->pdo, $this->scope))->build($this->user, $this->condo, $this->today);
        $add('Balanço: ativo − (passivo + PL)', 0, (int)($balance['difference_cents'] ?? $balance['diferenca'] ?? $this->findKey($balance, 'difference_cents')));
        $add('Balanço: disponível = soma dos bancos', $direct, (int)$this->findCode($balance, '7.1'));

        return ['condominium_id' => $this->condo, 'checks' => $checks, 'ok' => !in_array(false, array_column($checks, 'ok'), true)];
    }

    // ------------------------------------------------------------------ apoio

    private function bankRows(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, opening_balance_cents, opened_on FROM erp_bank_accounts WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id');
        $stmt->execute([$this->condo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Saldo de hoje direto das tabelas: abertura + receitas pagas − despesas pagas + transferências recebidas − enviadas. */
    private function directBalance(int $bank, int $opening, ?string $openedOn): int
    {
        $since = $openedOn ?? '1900-01-01';
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN nature = 'receita' THEN amount_cents ELSE -amount_cents END), 0) FROM erp_entries
            WHERE bank_account_id = :b AND status = 'active' AND deleted_at IS NULL AND paid_on IS NOT NULL AND paid_on >= :s AND paid_on <= :d");
        $stmt->execute(['b' => $bank, 's' => $since, 'd' => $this->today]);
        $net = (int)$stmt->fetchColumn();
        $transfers = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN to_bank_account_id = :b THEN amount_cents ELSE -amount_cents END), 0) FROM erp_bank_transfers
            WHERE (to_bank_account_id = :b2 OR from_bank_account_id = :b3) AND status = 'active' AND transferred_on >= :s AND transferred_on <= :d");
        $transfers->execute(['b' => $bank, 'b2' => $bank, 'b3' => $bank, 's' => $since, 'd' => $this->today]);
        return $opening + $net + (int)$transfers->fetchColumn();
    }

    private function sumAll(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['c' => $this->condo]);
        return (int)$stmt->fetchColumn();
    }

    private function unitId(string $label): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND CONCAT(block, '-', number) = ? AND deleted_at IS NULL");
        $stmt->execute([$this->condo, $label]);
        return (int)$stmt->fetchColumn();
    }

    /** Primeira chave com esse nome em qualquer nível (os relatórios têm formatos diferentes). */
    private function findKey(array $data, string $key): int
    {
        if (array_key_exists($key, $data) && is_numeric($data[$key])) {
            return (int)$data[$key];
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findKey($value, $key);
                if ($found !== PHP_INT_MIN) {
                    return $found;
                }
            }
        }
        return PHP_INT_MIN;
    }

    /** Valor da linha do relatório com esse código de conta. */
    private function findCode(array $data, string $code): int
    {
        if (($data['code'] ?? null) === $code) {
            foreach (['amount_cents', 'balance_cents', 'value_cents', 'total_cents'] as $key) {
                if (isset($data[$key]) && is_numeric($data[$key])) {
                    return (int)$data[$key];
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findCode($value, $code);
                if ($found !== PHP_INT_MIN) {
                    return $found;
                }
            }
        }
        return PHP_INT_MIN;
    }

    private function save(string $slug, array $data): int
    {
        try {
            $id = (int)$this->registry->save($this->user, $slug, $data)['id'];
        } catch (Registry\RegistryValidationException $error) {
            throw new DomainException("Cadastro {$slug} recusado: " . json_encode($error->errors, JSON_UNESCAPED_UNICODE), 0, $error);
        }
        $this->count($slug);
        return $id;
    }

    private function count(string $what, int $n = 1): void
    {
        $this->created[$what] = ($this->created[$what] ?? 0) + $n;
    }

    /** Data no mês da competência + $offset meses, no dia $day (limitado ao último dia), nunca depois de hoje. */
    private function monthDate(string $competence, int $offset, int $day): string
    {
        $month = (new \DateTimeImmutable($competence . '-01'))->modify("+{$offset} month");
        $day = min($day, (int)$month->format('t'));
        $date = $month->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        return min($date, $this->today);
    }

    private function account(string $code, string $nature, string $kind, string $name): int
    {
        $stmt = $this->pdo->prepare("SELECT id, nature, kind, condominium_id FROM erp_chart_accounts WHERE code = ? AND (condominium_id IS NULL OR condominium_id = ?)
            AND status = 'active' AND deleted_at IS NULL ORDER BY condominium_id IS NULL, id LIMIT 1");
        $stmt->execute([$code, $this->condo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && $row['nature'] === $nature && $row['kind'] === $kind) {
            return (int)$row['id'];
        }
        if ($row !== false) {
            throw new DomainException("A conta {$code} do plano de contas existe com outra natureza ou tipo ({$row['nature']}, {$row['kind']}); esperado {$nature}, {$kind}.");
        }
        return $this->save('chart', ['condominium_id' => $this->condo, 'code' => $code, 'nature' => $nature, 'kind' => $kind, 'name' => $name]);
    }

    private function supplier(string $name, string $category, int $i): int
    {
        $document = self::cnpj((string)(600120340000 + $i * 1013));
        $stmt = $this->pdo->prepare('SELECT id FROM erp_suppliers WHERE document = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$document]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        return $this->save('suppliers', ['legal_name' => $name, 'document' => $document, 'trade_name' => explode(' ', $name)[0], 'category' => $category,
                'email' => 'contato' . $i . '@fornecedor.example.com', 'phone' => '(16) 3300-61' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
    }

    private function employee(string $job, string $name, string $salary, string $shift, int $i): void
    {
        $this->save('employees', ['condominium_id' => $this->condo, 'job' => $job, 'name' => $name, 'document' => LabSampleData::cpf((string)(640000000 + $i * 37)),
                'hired_on' => '2023-0' . ($i + 2) . '-01', 'shift' => $shift, 'salary_cents' => $salary, 'phone' => '(16) 99200-61' . $i . '0']);
    }

    private function vehicle(int $unit, string $plate, string $kind, string $model, string $color, string $spot): void
    {
        $this->save('vehicles', ['condominium_id' => $this->condo, 'unit_id' => $unit, 'plate' => $plate, 'kind' => $kind, 'model' => $model, 'color' => $color, 'parking_spot' => $spot]);
    }

    private function asset(string $description, string $number, string $category, string $value): void
    {
        $this->save('assets', ['condominium_id' => $this->condo, 'description' => $description, 'asset_number' => $number, 'category' => $category, 'condition' => 'bom',
                'acquired_on' => '2022-06-01', 'value_cents' => $value, 'location' => 'Subsolo']);
    }

    /** Despesa da competência; paga (no mês seguinte, no dia indicado) quando $paid. */
    private function payable(string $competence, string $description, int $cents, string $code, ?string $supplier, int $day, ?string $document = null, bool $paid = true): void
    {
        $due = $this->monthDate($competence, 1, $day);
        if ($code === 'bomba') {
            $due = $this->monthDate($competence, 0, $day);
        }
        $this->entry('despesa', $competence, $description, $cents, $code, $due, $paid ? $due : null, $paid ? $this->banks['movimento'] : null, null,
            $supplier === null ? null : $this->suppliers[$supplier], $document);
    }

    private function entry(string $nature, string $competence, string $description, int $cents, string $code, string $due, ?string $paid, ?int $bank,
        ?int $unit = null, ?int $supplier = null, ?string $document = null): int
    {
        return $this->save('entries', ['condominium_id' => $this->condo, 'nature' => $nature, 'competence' => $competence, 'description' => $description,
            'amount_cents' => number_format($cents / 100, 2, ',', ''), 'chart_account_id' => $this->accounts[$code], 'due_on' => $due, 'paid_on' => $paid,
            'bank_account_id' => $bank, 'unit_id' => $unit, 'supplier_id' => $supplier, 'document_number' => $document]);
    }

    /** Baixa manual (Pix ou débito em conta) pela conta movimento. */
    private function payOff(int $entryId, string $paidOn, ?string $document = null): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $data = [];
        foreach (['condominium_id', 'nature', 'competence', 'description', 'chart_account_id', 'cost_center_id', 'due_on', 'supplier_id', 'unit_id', 'document_number', 'notes'] as $field) {
            $data[$field] = $row[$field];
        }
        $data['amount_cents'] = number_format(((int)$row['amount_cents']) / 100, 2, ',', '');
        $data['paid_on'] = min($paidOn, $this->today);
        $data['bank_account_id'] = $this->banks['movimento'];
        if ($document !== null) {
            $data['document_number'] = $document;
        }
        $this->registry->save($this->user, 'entries', $data, $entryId);
    }
}
