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
use Source\Support\Audit;

/**
 * Condomínios de teste por forma de rateio (docs/process/RATEIO_TEST_DATA.md, ADR 0640). Pedido do Djalma em 22/11/2026:
 * um condomínio para cada tipo de rateio, com o nome do tipo, alimentado de 1º de janeiro até hoje como em operação
 * normal, e uma auditoria contábil de tudo.
 *
 * create() cadastra pelos services que já existem (mesmas validações, escopo e auditoria das telas): condomínio,
 * unidades com fração, área, percentual e vagas, proprietários e inquilinos, plano de contas, bancos com saldo de
 * abertura, tipos de fatura, lançamentos fixos e, mês a mês: leituras de água e gás, faturas pela forma de rateio do
 * condomínio, boletos no banco de testes (sandbox), retorno com os pagamentos (em dia, atrasados com multa e juros,
 * por Pix), despesas, transferência do fundo de reserva, rendimento da poupança, fechamento do mês anterior no dia 5,
 * publicação da prestação de contas, acordo de quem ficou três meses sem pagar (com a reabertura dos meses, como o
 * sistema exige) e a quebra de um acordo.
 *
 * verify() confere, condomínio por condomínio, o rateio (soma 100%, cada unidade pela própria base, só quem
 * participa), o consumo, os boletos, a multa e os juros, os acordos, os saldos, os fechamentos, a inadimplência, a
 * prestação de contas e o balanço, contra somas diretas no banco. Cada linha traz o esperado e o encontrado.
 *
 * Só dados inventados: nomes, CPF e CNPJ gerados, e-mails em example.com. Nenhum banco real (adaptador sandbox).
 * Recusa criar de novo se algum dos condomínios já existir (use --remover antes).
 */
final class ApportionmentSample
{
    public const FIRST_MONTH = '2026-01';
    public const DUE_DAY = 10;
    /** Dia em que o mês anterior é fechado e a prestação de contas publicada. */
    public const CLOSING_DAY = 5;
    /** Acordo: quem deixou de pagar fevereiro a abril faz acordo em 20/05, em 6 parcelas a partir de 10/06. */
    public const AGREEMENT_ON = '2026-05-20';
    public const AGREEMENT_MONTHS = ['2026-02', '2026-03', '2026-04'];
    public const AGREEMENT_FIRST_DUE = '2026-06-10';
    public const AGREEMENT_INSTALLMENTS = 6;

    /** IPCA de teste (% ao mês), gravado só nos meses que ainda não têm índice no banco. */
    public const TEST_IPCA = ['2025-12' => 0.52, '2026-01' => 0.42, '2026-02' => 0.61, '2026-03' => 0.38, '2026-04' => 0.33, '2026-05' => 0.26,
        '2026-06' => 0.21, '2026-07' => 0.19, '2026-08' => 0.11, '2026-09' => 0.44, '2026-10' => 0.28];

    private PDO $pdo;
    private RegistryService $registry;
    private CondominiumScope $scope;
    private User $user;
    private string $today;
    /** @var array<string,int> contagem do que foi criado */
    private array $created = [];
    /** Estado do condomínio em construção. */
    private array $c = [];

    public function __construct(PDO $pdo, User $user, ?string $today = null)
    {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->registry = new RegistryService($pdo);
        $this->scope = new CondominiumScope($pdo);
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * Os condomínios de teste, um por forma de rateio (mais o misto, que junta várias como na vida real).
     * Valores em centavos. 'lines' são as faturas de todo mês: tipo, conta, forma de rateio, participantes e valor.
     */
    public static function scenarios(): array
    {
        return [
            'fracao' => ['name' => 'Condomínio Por Fração Ideal', 'legal' => 'Condomínio Edifício Jacarandá', 'city' => 'Ribeirão Preto', 'elevator' => true,
                'about' => 'Residencial de 48 apartamentos em 12 andares: tipo de 2 e de 3 quartos e coberturas, cada um paga pela fração ideal.',
                'batches' => [['blocks' => '', 'floor_from' => 1, 'floor_to' => 12, 'units_per_floor' => 4, 'type' => 'apartamento']],
                'area' => static fn (array $u): float => (string)$u['floor'] === '12' ? 140.0 : (in_array(substr((string)$u['number'], -2), ['01', '02'], true) ? 92.0 : 68.0),
                'fraction' => 'area',
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'fraction', 'amount' => 3_840_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'fraction', 'amount' => 384_000, 'fund' => true],
                    // Cota extra aprovada em assembleia: pintura da fachada em 3 meses, também pela fração.
                    ['type' => 'Cota extra — pintura da fachada', 'account' => 'extras', 'method' => 'fraction', 'amount' => 1_200_000, 'months' => ['2026-07', '2026-08', '2026-09']],
                ]],
            'igualitario' => ['name' => 'Condomínio Igualitário', 'legal' => 'Associação Residencial Vila das Palmeiras', 'city' => 'Sertãozinho', 'elevator' => false,
                'about' => 'Condomínio de 30 casas: todas pagam o mesmo valor, sem fração.',
                'batches' => [['blocks' => '', 'extra_numbers' => self::houses(30), 'type' => 'casa', 'private_area' => '150,00']],
                'fraction' => 'equal',
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'fixed', 'amount' => 78_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'fixed', 'amount' => 7_800, 'fund' => true],
                ]],
            'unidade' => ['name' => 'Condomínio Por Unidade', 'legal' => 'Condomínio Edifício Ipê Amarelo', 'city' => 'Ribeirão Preto', 'elevator' => true,
                'about' => '36 apartamentos; o total do mês é dividido pelas unidades participantes. O apartamento funcional do zelador (101) é isento, então o total divide por 35.',
                'batches' => [['blocks' => '', 'floor_from' => 1, 'floor_to' => 9, 'units_per_floor' => 4, 'type' => 'apartamento', 'private_area' => '70,00']],
                'fraction' => 'equal', 'exempt' => ['101'],
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'equal', 'amount' => 1_935_500],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'equal', 'amount' => 193_550, 'fund' => true],
                ]],
            'consumo' => ['name' => 'Condomínio Por Consumo Individual', 'legal' => 'Condomínio Residencial Águas Claras', 'city' => 'Ribeirão Preto', 'elevator' => true,
                'about' => '32 apartamentos com relógio de água e de gás em cada um: a água (tarifa progressiva) e o gás são cobrados pelo consumo lido no mês; a taxa ordinária é igual para todos.',
                'batches' => [['blocks' => '', 'floor_from' => 1, 'floor_to' => 8, 'units_per_floor' => 4, 'type' => 'apartamento', 'private_area' => '65,00']],
                'fraction' => 'equal', 'meters' => ['agua', 'gas'],
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'fixed', 'amount' => 45_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'fixed', 'amount' => 4_500, 'fund' => true],
                ]],
            'bloco' => ['name' => 'Condomínio Por Bloco ou Torre', 'legal' => 'Condomínio Parque das Torres', 'city' => 'Franca', 'elevator' => true,
                'about' => 'Três torres: A e B com 20 apartamentos e elevador, C com 10 e sem elevador. A taxa geral é de todos pela fração; as despesas de cada torre só das unidades dela.',
                'batches' => [['blocks' => 'A, B', 'floor_from' => 1, 'floor_to' => 10, 'units_per_floor' => 2, 'type' => 'apartamento'],
                    ['blocks' => 'C', 'floor_from' => 1, 'floor_to' => 5, 'units_per_floor' => 2, 'type' => 'apartamento']],
                'area' => static fn (array $u): float => $u['block'] === 'C' ? 60.0 : 72.0,
                'fraction' => 'area',
                'lines' => [
                    ['type' => 'Taxa geral (áreas comuns)', 'account' => 'taxa', 'method' => 'fraction', 'amount' => 2_000_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'fraction', 'amount' => 200_000, 'fund' => true],
                    ['type' => 'Despesas da Torre A', 'account' => 'extras', 'method' => 'equal', 'participants' => 'blocks', 'blocks' => ['A'], 'amount' => 300_000],
                    ['type' => 'Despesas da Torre B', 'account' => 'extras', 'method' => 'equal', 'participants' => 'blocks', 'blocks' => ['B'], 'amount' => 280_000],
                    ['type' => 'Despesas da Torre C', 'account' => 'extras', 'method' => 'equal', 'participants' => 'blocks', 'blocks' => ['C'], 'amount' => 90_000],
                ]],
            'vaga' => ['name' => 'Condomínio Por Vaga de Garagem', 'legal' => 'Condomínio Edifício Garagem Central', 'city' => 'Ribeirão Preto', 'elevator' => true,
                'about' => '30 apartamentos com 0 a 3 vagas cada (41 vagas). A taxa ordinária é pela fração; o rateio da garagem (portão, limpeza e iluminação do subsolo) é pelas vagas, e quem não tem vaga não paga.',
                'batches' => [['blocks' => '', 'floor_from' => 1, 'floor_to' => 10, 'units_per_floor' => 3, 'type' => 'apartamento', 'private_area' => '80,00']],
                'fraction' => 'equal', 'spots' => static fn (int $i): int => [1, 2, 1, 0, 1, 2, 1, 3, 1, 2][$i % 10],
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'fraction', 'amount' => 1_650_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'fraction', 'amount' => 165_000, 'fund' => true],
                    ['type' => 'Rateio da garagem', 'account' => 'extras', 'method' => 'parking', 'amount' => 410_000],
                ]],
            'percentual' => ['name' => 'Condomínio Por Percentual Definido', 'legal' => 'Condomínio Centro Empresarial Paulista', 'city' => 'Ribeirão Preto', 'elevator' => true,
                'about' => 'Prédio comercial com 4 lojas no térreo (8% cada) e 16 salas (4,25% cada), percentuais definidos na convenção que somam 100%.',
                'batches' => [['blocks' => '', 'extra_numbers' => 'Loja 01, Loja 02, Loja 03, Loja 04', 'type' => 'loja', 'private_area' => '120,00'],
                    ['blocks' => '', 'floor_from' => 1, 'floor_to' => 4, 'units_per_floor' => 4, 'type' => 'sala', 'private_area' => '45,00']],
                'fraction' => 'area', 'area' => static fn (array $u): float => str_starts_with((string)$u['number'], 'Loja') ? 120.0 : 45.0,
                'percent' => static fn (array $u): string => str_starts_with((string)$u['number'], 'Loja') ? '8,0000' : '4,2500',
                'breach' => true,
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'percent', 'amount' => 2_600_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'percent', 'amount' => 260_000, 'fund' => true],
                ]],
            'personalizado' => ['name' => 'Condomínio Personalizado', 'legal' => 'Condomínio Residencial Bela Vista', 'city' => 'Batatais', 'elevator' => true,
                'about' => '24 apartamentos. Além da taxa pela fração, a manutenção do terraço é só das 4 coberturas, por percentuais combinados (40, 30, 20 e 10%), e a academia é cobrada só de quem se inscreveu, com o valor de cada plano.',
                'batches' => [['blocks' => '', 'floor_from' => 1, 'floor_to' => 6, 'units_per_floor' => 4, 'type' => 'apartamento', 'private_area' => '75,00']],
                'fraction' => 'equal',
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'fraction', 'amount' => 1_440_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'fraction', 'amount' => 144_000, 'fund' => true],
                    ['type' => 'Manutenção do terraço (coberturas)', 'account' => 'extras', 'method' => 'custom', 'participants' => 'units', 'amount' => 120_000,
                        'percents' => ['601' => '40', '602' => '30', '603' => '20', '604' => '10']],
                    ['type' => 'Academia (planos)', 'account' => 'extras', 'method' => 'custom', 'participants' => 'units',
                        'amounts' => ['103' => 8_000, '204' => 12_000, '302' => 15_000, '401' => 8_000, '503' => 12_000, '602' => 15_000]],
                ]],
            'misto' => ['name' => 'Condomínio Misto', 'legal' => 'Condomínio Residencial Solar dos Ipês', 'city' => 'Ribeirão Preto', 'elevator' => true,
                'about' => 'O caso mais comum na vida real: duas torres de 20 apartamentos, taxa pela fração ideal, fundo de reserva igual para todos, água pelo consumo de cada unidade e garagem pelas vagas.',
                'batches' => [['blocks' => 'A, B', 'floor_from' => 1, 'floor_to' => 10, 'units_per_floor' => 2, 'type' => 'apartamento']],
                'area' => static fn (array $u): float => in_array((string)$u['floor'], ['9', '10'], true) ? 98.0 : 76.0, 'fraction' => 'area',
                'meters' => ['agua'], 'spots' => static fn (int $i): int => [1, 1, 2, 1, 0][$i % 5],
                'lines' => [
                    ['type' => 'Taxa condominial', 'account' => 'taxa', 'method' => 'fraction', 'amount' => 2_800_000],
                    ['type' => 'Fundo de reserva', 'account' => 'fundo', 'method' => 'equal', 'amount' => 280_000, 'fund' => true],
                    ['type' => 'Rateio da garagem', 'account' => 'extras', 'method' => 'parking', 'amount' => 210_000],
                ]],
        ];
    }

    private static function houses(int $n): string
    {
        return implode(', ', array_map(static fn (int $i): string => 'Casa ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), range(1, $n)));
    }

    /** Competências de janeiro até o mês de hoje (regra pura). @return list<string> */
    public static function months(string $today): array
    {
        $list = [];
        $month = new \DateTimeImmutable(self::FIRST_MONTH . '-01');
        $stop = substr($today, 0, 7);
        while ($month->format('Y-m') <= $stop) {
            $list[] = $month->format('Y-m');
            $month = $month->modify('+1 month');
        }
        return $list;
    }

    /** Participação 0 a 1 de cada peso, em milionésimos, com soma exata 1 (maiores restos; regra pura). @return array<int|string,string> */
    public static function fractions(array $weights): array
    {
        $cents = Invoices::apportion(1_000_000, $weights);
        return array_map(static fn (int $v): string => number_format($v / 1_000_000, 6, ',', ''), $cents);
    }

    /** @return array<string,int> nome => id dos condomínios de teste que existem */
    public function existing(): array
    {
        $names = array_column(self::scenarios(), 'name');
        $stmt = $this->pdo->prepare('SELECT name, id FROM condominiums WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY id');
        $stmt->execute($names);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @return array<string,mixed> */
    public function create(): array
    {
        $existing = $this->existing();
        if ($existing !== []) {
            throw new DomainException('Já existem condomínios de teste do rateio (' . implode(', ', array_keys($existing)) . '). Use --conferir ou --remover.');
        }
        $this->fillIndexes();
        $out = [];
        try {
            $n = 0;
            foreach (self::scenarios() as $key => $scenario) {
                $this->created = [];
                $this->build($key, $scenario, $n++);
                $out[$key] = $this->created + ['condominium_id' => $this->c['id']];
            }
        } catch (\Throwable $error) {
            // Os services abrem as próprias transações; se algo falhar no meio, apaga o que já entrou.
            $this->remove();
            throw $error;
        }
        return $out;
    }

    /** IPCA de teste só nos meses sem índice (não troca índice já lançado no laboratório). */
    private function fillIndexes(): void
    {
        $rules = new BillingRules($this->pdo, $this->scope);
        $have = $rules->rates('IPCA', array_key_first(self::TEST_IPCA), (string)array_key_last(self::TEST_IPCA));
        $missing = array_diff_key(self::TEST_IPCA, $have);
        if ($missing !== []) {
            $rules->saveRates($this->user, 'IPCA', $missing);
        }
    }

    // ------------------------------------------------------------------ montagem de um condomínio

    private function build(string $key, array $s, int $n): void
    {
        $months = self::months($this->today);
        $this->c = ['key' => $key, 's' => $s, 'n' => $n, 'units' => [], 'accounts' => [], 'banks' => [], 'types' => [], 'events' => [], 'suppliers' => []];
        $this->condominium();
        $this->unitsAndPeople();
        $this->chartAndBanks();
        if (!empty($s['meters'])) {
            $this->meters();
        }
        $last = count($months) - 1;
        foreach ($months as $i => $competence) {
            $this->month($competence, $i, $i === $last);
            if ($competence === substr(self::AGREEMENT_ON, 0, 7)) {
                $this->agreement();
            }
        }
        // Eventos que ainda faltam (pagamentos atrasados até hoje) e a situação de hoje.
        $this->processEvents('9999-12-31');
        $this->today();
    }

    private function condominium(): void
    {
        $s = $this->c['s'];
        $n = $this->c['n'];
        $units = array_sum(array_map(fn (array $b): int => count(UnitBatch::plan($b)['units']), $s['batches']));
        $blocks = array_unique(array_filter(array_merge(...array_map(static fn (array $b): array => array_map('trim', explode(',', (string)$b['blocks'])), $s['batches']))));
        $result = (new CondominiumRegistry($this->pdo, $this->scope))->create($this->user, [
            'name' => $s['name'], 'legal_name' => $s['legal'], 'document' => LafayetteSample::cnpj((string)(481230010000 + $n * 7919)),
            'status' => 'active', 'zip_code' => '14020-' . str_pad((string)(300 + $n * 11), 3, '0', STR_PAD_LEFT), 'street' => 'Rua dos Testes de Rateio',
            'number' => (string)(100 + $n * 50), 'complement' => '', 'district' => 'Jardim Laboratório', 'city' => $s['city'], 'state' => 'SP',
            'phone' => '(16) 3610-' . (5000 + $n), 'email' => 'sindico.rateio' . $n . '@example.com', 'blocks_count' => (string)max(1, count($blocks)),
            'units_count' => (string)$units, 'contract_start_date' => self::FIRST_MONTH . '-01', 'fee_due_day' => (string)self::DUE_DAY,
        ]);
        if (!$result['ok']) {
            throw new DomainException('Condomínio recusado: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        }
        $this->c['id'] = (int)$result['id'];
        if (!$this->scope->hasGlobalAccess($this->user)) {
            $this->scope->grant((int)$this->user->id, $this->c['id'], (int)$this->user->id);
        }
        $this->count('condominios');
    }

    private function unitsAndPeople(): void
    {
        $s = $this->c['s'];
        $condo = $this->c['id'];
        $batch = new UnitBatch($this->pdo, $this->scope);
        foreach ($s['batches'] as $b) {
            $batch->create($this->user, ['condominium_id' => $condo, 'digits' => 2] + $b);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL
            ORDER BY CHAR_LENGTH(COALESCE(block, '')), COALESCE(block, ''), CHAR_LENGTH(number), number");
        $stmt->execute([$condo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $this->c['units'][(string)$row['label']] = (int)$row['id'];
        }
        $this->count('unidades', count($rows));

        // Fração ideal (soma exata 1) pela área privativa ou igual, e a área de cada unidade.
        $weights = [];
        $areas = [];
        foreach ($rows as $row) {
            $area = isset($s['area']) ? ($s['area'])($row) : (float)($row['private_area'] ?? 70);
            $areas[(int)$row['id']] = $area;
            $weights[(int)$row['id']] = $s['fraction'] === 'area' ? $area : 1;
        }
        $changes = [];
        foreach (self::fractions($weights) as $id => $fraction) {
            $changes[] = ['id' => $id, 'ideal_fraction' => $fraction, 'private_area' => number_format($areas[$id], 2, ',', '')];
        }
        $batch->saveMeasures($this->user, $condo, $changes);

        // Percentual definido na convenção e unidade isenta de rateio: alteração do cadastro da unidade.
        foreach ($rows as $row) {
            $changes = [];
            if (isset($s['percent'])) {
                $changes['apportion_percent'] = ($s['percent'])($row);
            }
            if (in_array((string)$row['number'], $s['exempt'] ?? [], true)) {
                $changes['apportionment_exempt'] = '1';
                $changes['notes'] = 'Apartamento funcional do zelador: isento do rateio pela convenção.';
            }
            if ($changes !== []) {
                $this->updateUnit((int)$row['id'], $changes);
            }
        }

        // Proprietário em todas; inquilino em 1 de cada 4. Vagas de garagem quando o condomínio tem.
        $first = ['Helena', 'Otávio', 'Beatriz', 'Caio', 'Lívia', 'Murilo', 'Sofia', 'Vicente', 'Isadora', 'Joaquim', 'Clara', 'Henrique', 'Valentina', 'Benício',
            'Manuela', 'Davi', 'Alice', 'Bernardo', 'Laura', 'Gael', 'Cecília', 'Theo', 'Lorena', 'Heitor', 'Marina', 'Lucca', 'Antônia', 'Ravi', 'Elisa', 'Pietro'];
        $last = ['Prado', 'Almeida', 'Rocha', 'Vasconcelos', 'Moraes', 'Siqueira', 'Barros', 'Nogueira', 'Toledo', 'Freitas', 'Camargo', 'Queiroz', 'Teles', 'Bastos'];
        $seed = 700_000_000 + $this->c['n'] * 1_000_000;
        $i = 0;
        foreach ($rows as $row) {
            $unit = (int)$row['id'];
            $name = $first[($i * 7 + $this->c['n']) % count($first)] . ' ' . $last[($i * 3 + $this->c['n'] * 5) % count($last)] . ' ' . $last[($i + 4) % count($last)];
            $this->save('residents', ['condominium_id' => $this->c['id'], 'unit_id' => $unit, 'relationship' => 'owner', 'name' => $name,
                'document' => LabSampleData::cpf((string)($seed + $i * 13)), 'email' => 'proprietario.r' . $this->c['n'] . '.' . $i . '@example.com',
                'phone' => sprintf('(16) 99%03d-%04d', 100 + $this->c['n'], 1000 + $i), 'starts_on' => '2020-03-01', 'billing_by_email' => '1']);
            if ($i % 4 === 2) {
                $this->save('residents', ['condominium_id' => $this->c['id'], 'unit_id' => $unit, 'relationship' => 'tenant',
                    'name' => $first[($i * 11 + 3) % count($first)] . ' ' . $last[($i * 5 + 1) % count($last)],
                    'document' => LabSampleData::cpf((string)($seed + 500_000 + $i * 17)), 'email' => 'inquilino.r' . $this->c['n'] . '.' . $i . '@example.com',
                    'phone' => sprintf('(16) 98%03d-%04d', 100 + $this->c['n'], 2000 + $i), 'starts_on' => '2025-06-01']);
            }
            if (isset($s['spots'])) {
                for ($v = 1; $v <= ($s['spots'])($i); $v++) {
                    $this->save('linkable_areas', ['condominium_id' => $this->c['id'], 'kind' => 'vaga', 'identifier' => 'V-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . chr(64 + $v),
                        'unit_id' => $unit, 'location' => 'Subsolo']);
                }
            }
            $i++;
        }
    }

    /** Altera campos de uma unidade pelo motor de cadastros (o update exige o registro inteiro). */
    private function updateUnit(int $id, array $changes): void
    {
        $definition = Registry\Definitions::get('units');
        $stmt = $this->pdo->prepare('SELECT * FROM erp_units WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $data = [];
        foreach (array_keys($definition['fields']) as $field) {
            $value = $row[$field] ?? null;
            $type = $definition['fields'][$field]['type'];
            if ($value !== null && $type === 'decimal') {
                $value = str_replace('.', ',', (string)$value);
            } elseif ($value !== null && $type === 'money') {
                $value = number_format(((int)$value) / 100, 2, ',', '');
            }
            $data[$field] = $value;
        }
        $this->registry->save($this->user, 'units', array_merge($data, $changes), $id);
    }

    private function chartAndBanks(): void
    {
        $opened = self::FIRST_MONTH . '-01';
        foreach ([
            'receitas' => ['1', 'receita', 'sintetica', 'Receitas'], 'taxa' => ['1.01', 'receita', 'analitica', 'Taxa condominial'],
            'fundo' => ['1.02', 'receita', 'analitica', 'Fundo de reserva'], 'multas' => ['1.03', 'receita', 'analitica', 'Multas e juros'],
            'extras' => ['1.04', 'receita', 'analitica', 'Rateios e cotas extras'], 'rendimentos' => ['1.05', 'receita', 'analitica', 'Rendimentos de aplicação'],
            'agua_receita' => ['1.91', 'receita', 'analitica', 'Consumo de água'], 'acordos' => ['1.92', 'receita', 'analitica', 'Acordos'],
            'gas_receita' => ['1.93', 'receita', 'analitica', 'Consumo de gás'],
            'despesas' => ['2', 'despesa', 'sintetica', 'Despesas'], 'pessoal' => ['2.01', 'despesa', 'analitica', 'Pessoal e encargos'],
            'agua' => ['2.02', 'despesa', 'analitica', 'Água e esgoto'], 'energia' => ['2.03', 'despesa', 'analitica', 'Energia elétrica'],
            'manutencao' => ['2.04', 'despesa', 'sintetica', 'Manutenção e conservação'], 'eletrica' => ['2.04.01', 'despesa', 'analitica', 'Manutenção elétrica'],
            'elevadores' => ['2.04.03', 'despesa', 'analitica', 'Elevadores'], 'jardinagem' => ['2.04.04', 'despesa', 'analitica', 'Jardinagem'],
            'pintura' => ['2.04.05', 'despesa', 'analitica', 'Pintura e obras'],
            'limpeza' => ['2.05', 'despesa', 'analitica', 'Limpeza e material de consumo'], 'seguro' => ['2.06', 'despesa', 'analitica', 'Seguros'],
            'adm' => ['2.07', 'despesa', 'analitica', 'Administração e honorários'], 'tarifas' => ['2.08', 'despesa', 'analitica', 'Tarifas bancárias'],
            'gas' => ['2.91', 'despesa', 'analitica', 'Gás (concessionária)'],
            '8.2' => ['8.2', 'liability', 'analitica', 'Fundo de reserva'], '9.1' => ['9.1', 'equity', 'analitica', 'Resultados acumulados'],
        ] as $key => [$code, $nature, $kind, $name]) {
            $this->c['accounts'][$key] = $this->account($code, $nature, $kind, $name);
        }
        $monthly = $this->monthlyRevenue();
        $movement = (int)round($monthly * 1.2, -4);
        $reserve = (int)round($monthly * 3, -4);
        $n = $this->c['n'];
        $this->c['banks']['movimento'] = $this->save('bank_accounts', ['condominium_id' => $this->c['id'], 'name' => 'Conta movimento', 'bank_code' => '077', 'branch' => '0001',
            'account_number' => (string)(81000 + $n) . '-' . $n, 'kind' => 'corrente', 'wallet' => '112', 'opening_balance_cents' => $this->money($movement), 'opened_on' => $opened, 'is_main' => '1']);
        $this->c['banks']['reserva'] = $this->save('bank_accounts', ['condominium_id' => $this->c['id'], 'name' => 'Poupança do fundo de reserva', 'bank_code' => '077', 'branch' => '0001',
            'account_number' => (string)(92000 + $n) . '-' . $n, 'kind' => 'poupanca', 'opening_balance_cents' => $this->money($reserve), 'opened_on' => $opened]);
        $openings = new ChartOpenings($this->pdo, $this->scope);
        $openings->set($this->user, $this->c['id'], $this->c['accounts']['8.2'], $opened, $this->money($reserve), 'Saldo do fundo na implantação');
        $openings->set($this->user, $this->c['id'], $this->c['accounts']['9.1'], $opened, $this->money($movement), 'Saldo do caixa na implantação');
        $this->c['opening_cents'] = $movement + $reserve;
        foreach ($this->c['s']['lines'] as $line) {
            $this->c['types'][$line['type']] = $this->save('invoice_types', ['condominium_id' => $this->c['id'], 'name' => $line['type'],
                'chart_account_id' => $this->c['accounts'][$line['account']], 'recurrence' => isset($line['months']) ? 'avulsa' : 'mensal']);
        }
        foreach ([['agua', 'Saneamento Ribeirão S.A.', 'concessionaria'], ['energia', 'Energia Paulista Distribuidora', 'concessionaria'], ['elevador', 'Elevadores Altura Ltda', 'elevadores'],
            ['limpeza', 'Brilho Serviços de Limpeza', 'limpeza'], ['jardim', 'Jardins do Vale Paisagismo', 'jardinagem'], ['seguro', 'Proteção Total Seguros', 'outros'],
            ['gas', 'Gás Canalizado Interior S.A.', 'concessionaria'], ['pintura', 'Cores Vivas Pinturas Prediais', 'manutencao'], ['eletrica', 'Volt Manutenção Elétrica', 'manutencao']] as $i => [$k, $name, $category]) {
            $this->c['suppliers'][$k] = $this->supplier($name, $category, $i);
        }
        // Lançamentos fixos (AP-021): limpeza terceirizada e honorários da administradora.
        $this->save('recurring_entries', ['condominium_id' => $this->c['id'], 'nature' => 'despesa', 'description' => 'Limpeza terceirizada (contrato)',
            'amount_cents' => $this->money((int)round($monthly * 0.08, -2)), 'chart_account_id' => $this->c['accounts']['limpeza'], 'supplier_id' => $this->c['suppliers']['limpeza'],
            'bank_account_id' => $this->c['banks']['movimento'], 'day_of_month' => '15', 'starts_competence' => self::FIRST_MONTH]);
        $this->save('recurring_entries', ['condominium_id' => $this->c['id'], 'nature' => 'despesa', 'description' => 'Honorários da administradora',
            'amount_cents' => $this->money((int)round($monthly * 0.06, -2)), 'chart_account_id' => $this->c['accounts']['adm'],
            'bank_account_id' => $this->c['banks']['movimento'], 'day_of_month' => '5', 'starts_competence' => self::FIRST_MONTH]);
        $this->save('employees', ['condominium_id' => $this->c['id'], 'job' => 'zelador', 'name' => 'Zelador ' . $this->c['s']['legal'], 'document' => LabSampleData::cpf((string)(880000000 + $n * 37)),
            'hired_on' => '2022-03-01', 'shift' => 'comercial', 'salary_cents' => '3.100,00']);
    }

    /** Receita mensal das faturas fixas (sem as avulsas), para dimensionar despesas e saldos. */
    private function monthlyRevenue(): int
    {
        $total = 0;
        $count = count($this->c['units']);
        foreach ($this->c['s']['lines'] as $line) {
            if (isset($line['months'])) {
                continue;
            }
            $total += match (true) {
                $line['method'] === 'fixed' => $line['amount'] * ($count - count($this->c['s']['exempt'] ?? [])),
                isset($line['amounts']) => array_sum($line['amounts']),
                default => $line['amount'],
            };
        }
        if (!empty($this->c['s']['meters'])) {
            $total += $count * 9_000 * count($this->c['s']['meters']);
        }
        return $total;
    }

    /** Relógios de água e gás em cada unidade, com tarifa do condomínio. */
    private function meters(): void
    {
        $from = '2025-12-01';
        foreach ($this->c['s']['meters'] as $kind) {
            $bands = $kind === 'agua'
                // Faixas contínuas (a seguinte começa onde a anterior termina), como pede a cobertura das tarifas (TariffCoverage).
                ? [['Faixa 1', '0', '10', '7,80'], ['Faixa 2', '10', '20', '10,40'], ['Faixa 3', '20', '', '14,20']]
                : [['Gás canalizado', '0', '', '16,90']];
            foreach ($bands as [$name, $min, $max, $price]) {
                $this->save('tariffs', ['condominium_id' => $this->c['id'], 'kind' => $kind, 'name' => $name, 'from_amount' => $min, 'to_amount' => $max, 'price_cents' => $price, 'valid_from' => $from]);
            }
            $i = 0;
            foreach ($this->c['units'] as $unit) {
                $i++;
                $initial = ($kind === 'agua' ? 400 : 90) + $i * 13;
                $this->c['meters'][$kind][$unit] = ['id' => $this->save('meters', ['condominium_id' => $this->c['id'], 'unit_id' => $unit, 'kind' => $kind,
                    'number' => strtoupper(substr($kind, 0, 1)) . '-' . (50000 + $this->c['n'] * 1000 + $i), 'installed_on' => '2025-06-15', 'initial_reading' => (string)$initial]), 'value' => (float)$initial];
            }
        }
    }

    // ------------------------------------------------------------------ um mês de operação

    private function month(string $competence, int $index, bool $current): void
    {
        $condo = $this->c['id'];
        $due = $this->date($competence, 0, self::DUE_DAY);
        $receivables = [];
        // 1. Consumo: leitura no dia 25 do mês anterior, cobrada com vencimento no dia 10.
        foreach ($this->c['s']['meters'] ?? [] as $kind) {
            $readings = new MeterReadings($this->pdo, $this->scope);
            $i = 0;
            foreach ($this->c['meters'][$kind] as $unit => $meter) {
                $i++;
                $step = $kind === 'agua' ? 6 + (($i * 7 + $index * 5) % 19) : 1 + (($i * 3 + $index * 2) % 5) + (($i + $index) % 2) * 0.5;
                $this->c['meters'][$kind][$unit]['value'] = $meter['value'] + $step;
                $readings->record($this->user, ['meter_id' => $meter['id'], 'competence' => $competence, 'read_on' => $this->date($competence, -1, 25),
                    'reading' => number_format($this->c['meters'][$kind][$unit]['value'], 3, ',', '')]);
                $this->count('leituras');
            }
            $bill = $readings->bill($this->user, ['condominium_id' => $condo, 'competence' => $competence, 'kind' => $kind, 'due_on' => $due,
                'chart_account_id' => $this->c['accounts'][$kind === 'agua' ? 'agua_receita' : 'gas_receita']]);
            $receivables = array_merge($receivables, $bill['created']);
        }
        // 2. Faturas pela forma de rateio do condomínio.
        $invoices = new Invoices($this->pdo, $this->scope);
        foreach ($this->c['s']['lines'] as $line) {
            if (isset($line['months']) && !in_array($competence, $line['months'], true)) {
                continue;
            }
            $input = ['condominium_id' => $condo, 'invoice_type_id' => $this->c['types'][$line['type']], 'competence' => $competence, 'due_on' => $due,
                'method' => $line['method'], 'participants' => $line['participants'] ?? 'all'];
            if (isset($line['amount'])) {
                $input['amount_cents'] = $line['amount'];
            }
            if (isset($line['blocks'])) {
                $input['blocks'] = $line['blocks'];
            }
            if (isset($line['percents']) || isset($line['amounts'])) {
                $byLabel = $line['percents'] ?? $line['amounts'];
                $input['unit_ids'] = array_map(fn (string $label): int => $this->c['units'][$label], array_keys($byLabel));
                $mapped = [];
                foreach ($byLabel as $label => $value) {
                    $mapped[$this->c['units'][$label]] = $value;
                }
                $input[isset($line['percents']) ? 'unit_percents' : 'unit_amounts'] = $mapped;
            }
            $draft = $invoices->prepare($this->user, $input);
            foreach ($invoices->issue($this->user, (int)$draft['id'])['items'] as $item) {
                $receivables[] = (int)$item['entry_id'];
            }
            if (!empty($line['fund'])) {
                $this->c['fund_month'][$competence] = (int)$draft['amount_cents'];
            }
            $this->count('faturas');
        }
        // Parcelas de acordo que vencem neste mês.
        $stmt = $this->pdo->prepare("SELECT id FROM erp_entries WHERE condominium_id = ? AND agreement_role = 'installment' AND status = 'active' AND deleted_at IS NULL
            AND paid_on IS NULL AND competence = ? ORDER BY id");
        $stmt->execute([$condo, $competence]);
        $receivables = array_merge($receivables, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        // 3. Boletos (sandbox) e quando cada um é pago.
        $this->collect($receivables, $competence, $index);

        // 4. Despesas da competência e lançamentos fixos.
        $this->expenses($competence, $index);

        // 5. Fundo de reserva para a poupança e rendimento da poupança.
        $fund = $this->c['fund_month'][$competence] ?? 0;
        if ($fund > 0) {
            (new EntryShortcuts($this->pdo, $this->scope))->transfer($this->user, ['condominium_id' => $condo, 'from_bank_account_id' => $this->c['banks']['movimento'],
                'to_bank_account_id' => $this->c['banks']['reserva'], 'amount_cents' => $this->money($fund, '.'), 'transferred_on' => $this->date($competence, 0, 15),
                'description' => 'Fundo de reserva do mês para a poupança']);
            $this->count('transferencias');
        }
        $end = $this->date($competence, 0, 31);
        if (!$current) {
            $this->entry('receita', $competence, 'Rendimento da poupança do fundo', (int)round($this->bankBalance($this->c['banks']['reserva'], $end) * 0.0055), 'rendimentos',
                $end, $end, $this->c['banks']['reserva']);
        }

        // 6. Pagamentos até o dia 5, fechamento do mês anterior com a prestação de contas, e o resto do mês.
        $this->processEvents($this->date($competence, 0, self::CLOSING_DAY));
        if ($index > 0) {
            $previous = (new \DateTimeImmutable($competence . '-01'))->modify('-1 month')->format('Y-m');
            $this->close($previous);
        }
        $this->processEvents($this->date($competence, 0, 31));
    }

    /** Boletos das contas a receber e o pagamento de cada uma: em dia, atrasado (com multa e juros), por Pix ou em aberto. */
    private function collect(array $receivables, string $competence, int $index): void
    {
        $charges = new BankCharges($this->pdo, $this->scope);
        $labels = array_flip($this->c['units']);
        $positions = array_flip(array_values($this->c['units']));
        $stmt = $this->pdo->prepare('SELECT id, unit_id, amount_cents, due_on, agreement_role FROM erp_entries WHERE id = ?');
        $pix = [];
        foreach ($receivables as $entryId) {
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            $pos = $positions[(int)$entry['unit_id']] ?? 0;
            $profile = $this->profile($pos, $competence, $index, $entry['agreement_role'] === 'installment', (int)$entry['unit_id']);
            if ($profile['kind'] === 'pix') {
                $pix[$profile['paid_on']][] = $entryId;
                continue;
            }
            $charge = $charges->issue($this->user, $entryId, $this->c['banks']['movimento']);
            $this->count('boletos');
            if ($profile['kind'] === 'open') {
                continue;
            }
            $amount = (int)$charge['amount_cents'];
            $paidOn = $profile['paid_on'];
            if ($paidOn > $this->today) {
                continue;   // ainda não pagou
            }
            $fees = $paidOn > $entry['due_on'] ? LateCharges::compute($amount, (string)$entry['due_on'], $paidOn, ['fine_bp' => 200, 'monthly_interest_bp' => 100, 'price_index' => 'NONE'])['charges_cents'] : 0;
            $this->c['events'][] = ['event_key' => 'RAT-' . $this->c['key'] . '-' . $charge['our_number'], 'kind' => 'paid', 'our_number' => $charge['our_number'],
                'amount_cents' => $amount + $fees, 'occurred_on' => $paidOn];
            if ($fees > 0) {
                $this->count('pagamentos_atrasados');
            }
        }
        foreach ($pix as $paidOn => $ids) {
            if ($paidOn <= $this->today) {
                (new BatchSettlement($this->pdo, $this->scope))->settle($this->user, ['condominium_id' => $this->c['id'], 'bank_account_id' => $this->c['banks']['movimento'],
                    'paid_on' => $paidOn, 'entry_ids' => $ids], $this->today);
                $this->count('pagamentos_pix', count($ids));
            }
        }
        unset($labels);
    }

    /**
     * Comportamento de pagamento de uma unidade num mês (determinístico):
     * - posição 3: paga por Pix no dia 8 (baixa manual, sem boleto);
     * - posição 5: não paga fevereiro a abril (vira acordo); nas parcelas, paga em dia, menos no condomínio com quebra,
     *   em que paga só as duas primeiras;
     * - posição 9: não paga os dois últimos meses (inadimplente hoje);
     * - cerca de 1 em 9 boletos é pago atrasado, de 6 a 48 dias, com multa e juros;
     * - o resto paga em dia, entre o dia 3 e o vencimento.
     *
     * @return array{kind:string, paid_on?:string}
     */
    private function profile(int $pos, string $competence, int $index, bool $installment, int $unit): array
    {
        $months = self::months($this->today);
        $lastTwo = array_slice($months, -2);
        if ($installment) {
            $number = (int)$this->pdo->query('SELECT agreement_installment FROM erp_entries WHERE unit_id = ' . $unit . " AND agreement_role = 'installment' AND competence = '{$competence}' LIMIT 1")->fetchColumn();
            if (!empty($this->c['s']['breach']) && $number > 2) {
                return ['kind' => 'open'];
            }
            return ['kind' => 'paid', 'paid_on' => $this->date($competence, 0, 9)];
        }
        if ($pos === 5 && in_array($competence, self::AGREEMENT_MONTHS, true)) {
            return ['kind' => 'open'];
        }
        if ($pos === 9 && in_array($competence, $lastTwo, true)) {
            return ['kind' => 'open'];
        }
        if ($pos === 3) {
            return ['kind' => 'pix', 'paid_on' => $this->date($competence, 0, 8)];
        }
        $hash = ($pos * 7 + $index * 13 + $this->c['n'] * 5) % 9;
        if ($hash === 0) {
            $days = 6 + (($pos * 11 + $index * 7) % 43);
            return ['kind' => 'paid', 'paid_on' => (new \DateTimeImmutable($this->date($competence, 0, self::DUE_DAY)))->modify("+{$days} days")->format('Y-m-d')];
        }
        return ['kind' => 'paid', 'paid_on' => $this->date($competence, 0, 3 + (($pos + $index) % 8))];
    }

    /** Processa num arquivo de retorno os pagamentos com data até $until (os de datas seguintes ficam para depois). */
    private function processEvents(string $until): void
    {
        $until = min($until, $this->today);
        $now = array_values(array_filter($this->c['events'], static fn (array $e): bool => $e['occurred_on'] <= $until));
        if ($now === []) {
            return;
        }
        $this->c['events'] = array_values(array_filter($this->c['events'], static fn (array $e): bool => $e['occurred_on'] > $until));
        usort($now, static fn (array $a, array $b): int => [$a['occurred_on'], $a['our_number']] <=> [$b['occurred_on'], $b['our_number']]);
        $result = (new BankReturns($this->pdo, $this->scope))->process($this->user, $this->c['banks']['movimento'], 'rateio-' . $this->c['key'] . '-' . $until . '.ret',
            SandboxBankAdapter::buildReturn($now));
        $this->count('retornos');
        foreach ($result['events'] as $event) {
            if (($event['outcome'] ?? '') !== 'applied') {
                throw new DomainException($this->c['s']['name'] . ': o retorno até ' . $until . ' não baixou um título: ' . json_encode($event, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    private function expenses(string $competence, int $index): void
    {
        $r = $this->monthlyRevenue();
        $wave = static fn (int $base, int $spread) => $base + (($index * 7_919 + $base) % max(1, $spread)) - intdiv($spread, 2);
        $this->payable($competence, 'Folha de pagamento', (int)round($r * 0.38, -2), 'pessoal', null, 5);
        $this->payable($competence, 'INSS e FGTS', (int)round($r * 0.12, -2), 'pessoal', null, 20);
        $this->payable($competence, 'Energia elétrica das áreas comuns', $wave((int)round($r * 0.07), (int)round($r * 0.02)), 'energia', 'energia', 25, 'NF ' . (5100 + $index));
        if (in_array('agua', $this->c['s']['meters'] ?? [], true)) {
            // Conta da concessionária = consumo das unidades + 8% de área comum, a R$ 9,40 o m³.
            $this->payable($competence, 'Conta de água e esgoto (concessionária)', $this->utility($competence, 'agua', 940, 1.08), 'agua', 'agua', 22, 'FAT-A' . $index);
        } else {
            $this->payable($competence, 'Conta de água e esgoto (concessionária)', $wave((int)round($r * 0.09), (int)round($r * 0.03)), 'agua', 'agua', 22, 'FAT-A' . $index);
        }
        if (in_array('gas', $this->c['s']['meters'] ?? [], true)) {
            $this->payable($competence, 'Gás canalizado (concessionária)', $this->utility($competence, 'gas', 1_480, 1.03), 'gas', 'gas', 18, 'FAT-G' . $index);
        }
        if ($this->c['s']['elevator']) {
            $this->payable($competence, 'Manutenção dos elevadores', (int)round($r * 0.04, -2), 'elevadores', 'elevador', 12, 'NF ' . (9300 + $index));
        }
        $this->payable($competence, 'Jardinagem', (int)round($r * 0.025, -2), 'jardinagem', 'jardim', 15, 'RPA ' . (70 + $index));
        if ($index % 3 === 1) {
            $this->payable($competence, 'Manutenção elétrica preventiva', (int)round($r * 0.03, -2), 'eletrica', 'eletrica', 18, 'NF ' . (4400 + $index));
        }
        if ($index === 0) {
            $this->payable($competence, 'Seguro predial (apólice anual)', (int)round($r * 0.45, -2), 'seguro', 'seguro', 20, 'APL ' . $this->c['key'] . '-2026');
        }
        if (in_array($competence, ['2026-07', '2026-08', '2026-09'], true) && $this->c['key'] === 'fracao') {
            $this->payable($competence, 'Pintura da fachada (parcela da obra)', 1_150_000, 'pintura', 'pintura', 20, 'NF ' . (7700 + $index));
        }
        $this->payable($competence, 'Tarifas bancárias (boletos)', 2_50 * count($this->c['units']) + 3_990, 'tarifas', null, 28);
        $generated = (new RecurringEntries($this->pdo, $this->scope))->generate($this->user, $competence, $this->c['id']);
        foreach ($generated['created'] ?? [] as $entry) {
            $id = (int)(is_array($entry) ? ($entry['id'] ?? $entry['entry_id']) : $entry);
            $row = $this->pdo->query('SELECT due_on FROM erp_entries WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
            if ((string)$row['due_on'] <= $this->today) {
                (new BatchSettlement($this->pdo, $this->scope))->settle($this->user, ['condominium_id' => $this->c['id'], 'bank_account_id' => $this->c['banks']['movimento'],
                    'paid_on' => (string)$row['due_on'], 'entry_ids' => [$id]], $this->today);
            }
            $this->count('lancamentos_fixos');
        }
    }

    /** Conta da concessionária: consumo lido no mês × fator de área comum × preço do m³. */
    private function utility(string $competence, string $kind, int $priceCents, float $factor): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(r.consumption), 0) FROM erp_meter_readings r JOIN erp_meters m ON m.id = r.meter_id
            WHERE r.condominium_id = ? AND r.competence = ? AND m.kind = ?');
        $stmt->execute([$this->c['id'], $competence, $kind]);
        return (int)round((float)$stmt->fetchColumn() * $factor * $priceCents);
    }

    /** Fecha o mês e publica a prestação de contas (AP-030, ADR 0031 e 0179). */
    private function close(string $competence): void
    {
        (new MonthClosing($this->pdo, $this->scope))->close($this->user, $this->c['id'], $competence);
        $this->count('fechamentos');
        (new AccountabilityPublications($this->pdo, $this->scope))->publish($this->user, $this->c['id'], $competence, 'Prestação de contas de teste (rateio)');
        $this->count('prestacoes_publicadas');
    }

    /**
     * Acordo da unidade que ficou três meses sem pagar. Como o sistema pede: os boletos vencidos são cancelados, os meses
     * já fechados são reabertos com motivo, o acordo é feito com os encargos sugeridos (correção pelo IPCA, multa de 2% e
     * juros de 1% ao mês) e os meses são fechados de novo, com nova versão da prestação de contas.
     */
    private function agreement(): void
    {
        $unit = array_values($this->c['units'])[5];
        $in = "'" . implode("','", self::AGREEMENT_MONTHS) . "'";
        $ids = array_map('intval', $this->pdo->query("SELECT id FROM erp_entries WHERE unit_id = {$unit} AND nature = 'receita' AND status = 'active' AND paid_on IS NULL
            AND deleted_at IS NULL AND competence IN ({$in}) ORDER BY due_on, id")->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return;
        }
        $charges = new BankCharges($this->pdo, $this->scope);
        foreach ($this->pdo->query('SELECT id FROM erp_bank_charges WHERE entry_id IN (' . implode(',', $ids) . ") AND status IN ('pending','registered')")->fetchAll(PDO::FETCH_COLUMN) as $charge) {
            $charges->cancel($this->user, (int)$charge);
            $this->count('boletos_cancelados');
        }
        $closing = new MonthClosing($this->pdo, $this->scope);
        $reopened = [];
        foreach (self::AGREEMENT_MONTHS as $competence) {
            if (($closing->status($this->user, $this->c['id'], $competence)['status'] ?? '') === 'closed') {
                $closing->reopen($this->user, $this->c['id'], $competence, 'Acordo da unidade ' . array_flip($this->c['units'])[$unit] . ' (dados de teste do rateio)');
                $reopened[] = $competence;
                $this->count('reaberturas');
            }
        }
        $agreements = new Agreements($this->pdo, $this->scope);
        $suggestion = $agreements->suggestion($this->user, $unit, $ids, self::AGREEMENT_ON);
        $agreements->create($this->user, ['unit_id' => $unit, 'entry_ids' => $ids, 'installments' => self::AGREEMENT_INSTALLMENTS, 'first_due_on' => self::AGREEMENT_FIRST_DUE,
            'chart_account_id' => $this->c['accounts']['acordos'], 'fees_cents' => $this->money((int)$suggestion['fees_cents']),
            'notes' => 'Acordo de teste do rateio, encargos sugeridos em ' . self::AGREEMENT_ON]);
        $this->count('acordos');
        foreach ($reopened as $competence) {
            $this->close($competence);
        }
    }

    /** Situação de hoje: contato de cobrança do inadimplente e quebra do acordo atrasado. */
    private function today(): void
    {
        $unit = array_values($this->c['units'])[9];
        (new Collections($this->pdo, $this->scope))->recordContact($this->user, ['unit_id' => $unit, 'channel' => 'whatsapp', 'outcome' => 'promise',
            'note' => 'Proprietário informou que paga os dois meses em aberto até o fim do mês.', 'contact_name' => 'Proprietário',
            'promise_on' => (new \DateTimeImmutable($this->today))->modify('+8 days')->format('Y-m-d'), 'promise_cents' => '1.000,00']);
        $this->count('contatos_cobranca');
        if (!empty($this->c['s']['breach'])) {
            $agreements = new Agreements($this->pdo, $this->scope);
            foreach ($this->pdo->query("SELECT id FROM erp_agreements WHERE condominium_id = {$this->c['id']} AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if ($agreements->breachStatus($this->user, (int)$id, $this->today)['due']) {
                    $agreements->breach($this->user, (int)$id, 'Duas parcelas vencidas sem pagamento (dados de teste)', $this->today);
                    $this->count('acordos_quebrados');
                }
            }
        }
    }

    // ------------------------------------------------------------------ remoção

    /**
     * Apaga os condomínios de teste do rateio (linhas com condominium_id deles e as filhas), numa transação. Fornecedores
     * são da administradora e ficam; a auditoria e os índices de preço também.
     *
     * @return array<string,int> linhas apagadas por tabela
     */
    public function remove(): array
    {
        $ids = array_values($this->existing());
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
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
                $stmt = $this->pdo->prepare('DELETE FROM ' . $quote($table) . ' WHERE ' . $quote($column) . ' IN (SELECT id FROM ' . $quote($parent) . " WHERE condominium_id IN ({$in}))");
                $stmt->execute();
                $removed[$table] = ($removed[$table] ?? 0) + $stmt->rowCount();
            }
            foreach ($scoped as $table) {
                $removed[$table] = (int)$this->pdo->exec('DELETE FROM ' . $quote($table) . " WHERE condominium_id IN ({$in})");
            }
            $removed['condominiums'] = (int)$this->pdo->exec("DELETE FROM condominiums WHERE id IN ({$in})");
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            throw $error;
        }
        foreach ($ids as $id) {
            Audit::record('delete', 'condominiums', $id, null, ['reason' => 'dados de teste do rateio (ADR 0640)']);
        }
        return array_filter($removed);
    }

    // ------------------------------------------------------------------ auditoria

    /**
     * Auditoria contábil de cada condomínio de teste. Cada linha: área, o que se confere, esperado, encontrado e ok.
     *
     * @return array{condominiums:array<string, array{id:int, name:string, checks:list<array>}>, ok:bool, total:int, failed:int}
     */
    public function verify(): array
    {
        $existing = $this->existing();
        if ($existing === []) {
            throw new DomainException('Os condomínios de teste do rateio não existem. Rode a criação primeiro.');
        }
        $out = [];
        $failed = 0;
        $total = 0;
        foreach (self::scenarios() as $key => $s) {
            if (!isset($existing[$s['name']])) {
                continue;
            }
            $checks = (new ApportionmentAudit($this->pdo, $this->user, $existing[$s['name']], $s, $this->today))->run();
            $out[$key] = ['id' => $existing[$s['name']], 'name' => $s['name'], 'about' => $s['about'], 'checks' => $checks];
            $total += count($checks);
            $failed += count(array_filter($checks, static fn (array $c): bool => !$c['ok']));
        }
        return ['condominiums' => $out, 'ok' => $failed === 0, 'total' => $total, 'failed' => $failed];
    }

    // ------------------------------------------------------------------ apoio

    private function save(string $slug, array $data): int
    {
        try {
            $id = (int)$this->registry->save($this->user, $slug, $data)['id'];
        } catch (Registry\RegistryValidationException $error) {
            throw new DomainException(($this->c['s']['name'] ?? '') . ": cadastro {$slug} recusado: " . json_encode($error->errors, JSON_UNESCAPED_UNICODE), 0, $error);
        }
        $this->count($slug);
        return $id;
    }

    private function count(string $what, int $n = 1): void
    {
        $this->created[$what] = ($this->created[$what] ?? 0) + $n;
    }

    private function money(int $cents, string $thousands = ''): string
    {
        return number_format($cents / 100, 2, ',', $thousands);
    }

    /** Data no mês da competência + $offset meses, no dia $day (limitado ao último dia do mês). */
    private function date(string $competence, int $offset, int $day): string
    {
        $month = (new \DateTimeImmutable($competence . '-01'))->modify("{$offset} month");
        return $month->format('Y-m-') . str_pad((string)min($day, (int)$month->format('t')), 2, '0', STR_PAD_LEFT);
    }

    /** Saldo direto de uma conta bancária numa data (abertura + baixas + transferências). */
    private function bankBalance(int $bank, string $on): int
    {
        $stmt = $this->pdo->prepare("SELECT opening_balance_cents
            + COALESCE((SELECT SUM(CASE WHEN nature = 'receita' THEN amount_cents ELSE -amount_cents END) FROM erp_entries WHERE bank_account_id = :b AND status = 'active'
                AND deleted_at IS NULL AND paid_on IS NOT NULL AND paid_on <= :d), 0)
            + COALESCE((SELECT SUM(CASE WHEN to_bank_account_id = :b2 THEN amount_cents ELSE -amount_cents END) FROM erp_bank_transfers WHERE (to_bank_account_id = :b3
                OR from_bank_account_id = :b4) AND status = 'active' AND transferred_on <= :d2), 0) FROM erp_bank_accounts WHERE id = :b5");
        $stmt->execute(['b' => $bank, 'd' => $on, 'b2' => $bank, 'b3' => $bank, 'b4' => $bank, 'd2' => $on, 'b5' => $bank]);
        return (int)$stmt->fetchColumn();
    }

    private function account(string $code, string $nature, string $kind, string $name): int
    {
        $stmt = $this->pdo->prepare("SELECT id, nature, kind FROM erp_chart_accounts WHERE code = ? AND (condominium_id IS NULL OR condominium_id = ?)
            AND status = 'active' AND deleted_at IS NULL ORDER BY condominium_id IS NULL, id LIMIT 1");
        $stmt->execute([$code, $this->c['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && $row['nature'] === $nature && $row['kind'] === $kind) {
            return (int)$row['id'];
        }
        if ($row !== false) {
            throw new DomainException("A conta {$code} do plano de contas existe com outra natureza ou tipo ({$row['nature']}, {$row['kind']}); esperado {$nature}, {$kind}.");
        }
        return $this->save('chart', ['condominium_id' => $this->c['id'], 'code' => $code, 'nature' => $nature, 'kind' => $kind, 'name' => $name]);
    }

    private function supplier(string $name, string $category, int $i): int
    {
        $document = LafayetteSample::cnpj((string)(610340120000 + $i * 1_117));
        $stmt = $this->pdo->prepare('SELECT id FROM erp_suppliers WHERE document = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$document]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        return $this->save('suppliers', ['legal_name' => $name, 'document' => $document, 'trade_name' => explode(' ', $name)[0], 'category' => $category,
            'email' => 'contato.rateio' . $i . '@fornecedor.example.com', 'phone' => '(16) 3300-71' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)]);
    }

    /** Despesa da competência, vencendo no mês seguinte no dia indicado; paga no vencimento se já chegou. */
    private function payable(string $competence, string $description, int $cents, string $code, ?string $supplier, int $day, ?string $document = null): void
    {
        $due = $this->date($competence, $day <= 10 ? 1 : 0, $day);
        $paid = $due <= $this->today;
        $this->entry('despesa', $competence, $description, max(1, $cents), $code, $due, $paid ? $due : null, $paid ? $this->c['banks']['movimento'] : null, null,
            $supplier === null ? null : $this->c['suppliers'][$supplier], $document);
    }

    private function entry(string $nature, string $competence, string $description, int $cents, string $code, string $due, ?string $paid, ?int $bank,
        ?int $unit = null, ?int $supplier = null, ?string $document = null): int
    {
        return $this->save('entries', ['condominium_id' => $this->c['id'], 'nature' => $nature, 'competence' => $competence, 'description' => $description,
            'amount_cents' => $this->money($cents), 'chart_account_id' => $this->c['accounts'][$code], 'due_on' => $due, 'paid_on' => $paid,
            'bank_account_id' => $bank, 'unit_id' => $unit, 'supplier_id' => $supplier, 'document_number' => $document]);
    }
}
