<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Importações (ADR 0058): prévia, erro bloqueia tudo, idempotência por hash, frações, OFX conciliado e escopo. */
final class ImportsIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private Imports $imports;
    private string $root;
    private int $bank;
    private int $paidEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $revenue = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza']);
        foreach (['101', '102'] as $number) {
            $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento']);
        }
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->paidEntry = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $revenue,
            'description' => 'Taxa 101', 'amount_cents' => '500,00', 'competence' => '2026-11', 'due_on' => '2026-11-05', 'paid_on' => '2026-11-04', 'bank_account_id' => $this->bank])['id'];
        $this->root = sys_get_temp_dir() . '/imports-test-' . bin2hex(random_bytes(4));
        $this->imports = new Imports($this->pdo, null, $this->root, true);
    }

    public function testEntriesPreviewApplyOnceAndErrorsBlockEverything(): void
    {
        $bad = "natureza;descricao;valor;competencia;vencimento;conta;unidade\nreceita;Taxa 101;500,00;11/2026;10/11/2026;1.01;101\nreceita;Taxa 999;10,00;11/2026;10/11/2026;1.01;999\n";
        $preview = $this->imports->preview($this->admin, 'entries', ['condominium_id' => $this->alpha], $this->upload($bad, 'lancamentos.csv'));
        self::assertSame([2, 1], [$preview['rows_count'], $preview['error_count']]);
        self::assertStringContainsString('999', $preview['preview'][1]['errors'][0]);
        try {
            $this->imports->apply($this->admin, $preview['id']);
            self::fail('Aplicou arquivo com erro.');
        } catch (DomainException) {
            self::assertSame(1, $this->countEntries(), 'Nada gravado.');
        }

        $good = "natureza;descricao;valor;competencia;vencimento;conta;unidade;pago_em;conta_bancaria\n"
            . "receita;Taxa 101;500,00;11/2026;10/11/2026;1.01;101;;\ndespesa;Limpeza;1.200,00;11/2026;05/11/2026;2.01;;05/11/2026;Movimento\n";
        $preview = $this->imports->preview($this->admin, 'entries', ['condominium_id' => $this->alpha], $this->upload($good, 'lancamentos.csv'));
        self::assertSame([0, 170000], [$preview['error_count'], $preview['summary']['total_cents']]);
        self::assertSame(1, $this->countEntries(), 'A prévia não lança.');
        $applied = $this->imports->apply($this->admin, $preview['id']);
        self::assertSame(['applied', 2], [$applied['status'], $applied['summary']['applied']['entries']]);
        self::assertSame(3, $this->countEntries());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_entries WHERE import_id = {$preview['id']}")->fetchColumn());

        $this->expectException(DomainException::class);
        $this->imports->preview($this->admin, 'entries', ['condominium_id' => $this->alpha], $this->upload($good, 'de-novo.csv'));
    }

    public function testFractionsNeedAllUnitsSummingOne(): void
    {
        $partial = $this->imports->preview($this->admin, 'fractions', ['condominium_id' => $this->alpha], $this->upload("unidade;fracao\n101;0,5\n", 'f.csv'));
        self::assertSame(1, $partial['error_count'], 'Falta a 102.');
        $wrong = $this->imports->preview($this->admin, 'fractions', ['condominium_id' => $this->alpha], $this->upload("unidade;fracao\n101;0,5\n102;0,4\n", 'f.csv'));
        self::assertSame(1, $wrong['error_count'], 'Soma 0,9.');
        $ok = $this->imports->preview($this->admin, 'fractions', ['condominium_id' => $this->alpha], $this->upload("unidade;fracao\n101;40%\n102;0,6\n", 'f.csv'));
        $this->imports->apply($this->admin, $ok['id']);
        self::assertSame(['0.400000', '0.600000'], $this->pdo->query("SELECT ideal_fraction FROM erp_units WHERE condominium_id = {$this->alpha} ORDER BY label")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testOfxMatchesPaidEntryAndLeavesTheRestPending(): void
    {
        $ofx = "<OFX><BANKACCTFROM><BANKID>001<ACCTID>56789</BANKACCTFROM><BANKTRANLIST>"
            . "<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261105<TRNAMT>500.00<FITID>X1<MEMO>PIX 101</STMTTRN>"
            . "<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261106<TRNAMT>-12.90<FITID>X2<MEMO>TARIFA</STMTTRN></BANKTRANLIST></OFX>";
        $preview = $this->imports->preview($this->admin, 'ofx', ['condominium_id' => $this->alpha, 'bank_account_id' => $this->bank], $this->upload($ofx, 'extrato.ofx'));
        self::assertSame([2, 0], [$preview['rows_count'], $preview['error_count']]);
        $applied = $this->imports->apply($this->admin, $preview['id']);
        self::assertSame(['lines' => 2, 'matched' => 1, 'unmatched' => 1, 'already' => 0], $applied['summary']['applied']);
        self::assertSame($this->paidEntry, $applied['lines'][0]['entry_id']);
        self::assertSame(1, $this->countEntries(), 'O extrato não cria lançamento.');

        $pending = $this->imports->pendingLines($this->admin, $this->alpha);
        self::assertSame(['X2'], array_column($pending['data'], 'fitid'));
        $resolved = $this->imports->resolveLine($this->admin, $pending['data'][0]['id'], 'ignore', null, 'Tarifa lançada no fechamento');
        self::assertSame('ignored', $resolved['status']);

        // Outra conta: o extrato não confere.
        $other = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '1111-1', 'kind' => 'poupanca'])['id'];
        $this->expectException(\InvalidArgumentException::class);
        $this->imports->preview($this->admin, 'ofx', ['condominium_id' => $this->alpha, 'bank_account_id' => $other], $this->upload($ofx . ' ', 'extrato.ofx'));
    }

    public function testReadingsImportUsesMeterRulesAllOrNothing(): void
    {
        $units = $this->pdo->query("SELECT id FROM erp_units WHERE condominium_id = {$this->alpha} ORDER BY label")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ([['A-1', $units[0]], ['A-2', $units[1]]] as [$number, $unit]) {
            $this->service->save($this->admin, 'meters', ['condominium_id' => $this->alpha, 'unit_id' => $unit, 'kind' => 'agua', 'number' => $number, 'initial_reading' => '100']);
        }
        $competence = date('m/Y');
        $bad = "relogio;tipo;competencia;leitura\nA-1;água;{$competence};110\nA-2;água;{$competence};90\n";
        $preview = $this->imports->preview($this->admin, 'readings', ['condominium_id' => $this->alpha], $this->upload($bad, 'leituras.csv'));
        self::assertSame(1, $preview['error_count'], 'Leitura menor que a inicial.');
        try {
            $this->imports->apply($this->admin, $preview['id']);
            self::fail('Aplicou leituras com erro.');
        } catch (DomainException) {
            self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_meter_readings')->fetchColumn(), 'Nada gravado.');
        }
        $good = "relogio;tipo;competencia;leitura\nA-1;água;{$competence};110,5\nA-2;Água;{$competence};120\n";
        $preview = $this->imports->preview($this->admin, 'readings', ['condominium_id' => $this->alpha], $this->upload($good, 'leituras.csv'));
        self::assertSame([0, 30.5], [$preview['error_count'], $preview['summary']['consumption']]);
        $applied = $this->imports->apply($this->admin, $preview['id']);
        self::assertSame(2, $applied['summary']['applied']['readings']);
        self::assertSame(['10.500', '20.000'], $this->pdo->query('SELECT consumption FROM erp_meter_readings ORDER BY meter_id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testOfxBalanceCheckOnlyShowsDifference(): void
    {
        $ofx = "<OFX><BANKACCTFROM><BANKID>001<ACCTID>56789</BANKACCTFROM><BANKTRANLIST>"
            . "<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261105<TRNAMT>500.00<FITID>B1</STMTTRN></BANKTRANLIST>"
            . "<LEDGERBAL><BALAMT>480.00<DTASOF>20261105</LEDGERBAL></OFX>";
        $preview = $this->imports->preview($this->admin, 'ofx', ['condominium_id' => $this->alpha, 'bank_account_id' => $this->bank], $this->upload($ofx, 'saldo.ofx'));
        self::assertSame(['bank_cents' => 48000, 'system_cents' => 50000, 'difference_cents' => -2000, 'matches' => false], $preview['summary']['balance_check']);
        $this->imports->apply($this->admin, $preview['id']);
        self::assertSame(1, $this->countEntries(), 'A diferença não gera lançamento de ajuste.');
    }

    public function testScope(): void
    {
        $outsider = $this->user([$this->beta]);
        $preview = $this->imports->preview($this->admin, 'fractions', ['condominium_id' => $this->alpha], $this->upload("unidade;fracao\n101;0,5\n102;0,5\n", 'f.csv'));
        self::assertSame(0, $this->imports->list($outsider)['total']);
        $this->expectException(RegistryNotFound::class);
        $this->imports->apply($outsider, $preview['id']);
    }

    private function upload(string $content, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function countEntries(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_entries WHERE condominium_id = {$this->alpha} AND deleted_at IS NULL")->fetchColumn();
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
