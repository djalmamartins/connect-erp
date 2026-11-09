<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use ZipArchive;

/**
 * Importações de ponta a ponta (ciclo 26, ADR 0058), encadeadas com o financeiro: frações por XLSX
 * → fatura rateada pela fração → boleto e retorno dão baixa → inadimplentes antigos por planilha
 * entram na inadimplência → extrato OFX concilia a baixa e a transferência, e o que sobra é
 * conciliado à mão → o mesmo FITID em outro arquivo não duplica. No fim, um usuário de outro
 * condomínio não alcança nada, e conta bancária de outro condomínio não serve para o extrato.
 */
final class ImportsCycleIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private Imports $imports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->imports = new Imports($this->pdo, null, sys_get_temp_dir() . '/imports-cycle-' . bin2hex(random_bytes(4)), true);
    }

    public function testSpreadsheetsAndStatementThroughTheFinanceCycle(): void
    {
        $today = date('Y-m-d');
        $due = date('Y-m-d', strtotime('+10 days'));
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $cleaning = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        $units = [];
        foreach (['101', '102'] as $number) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $bankA = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $bankB = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '9999-0', 'kind' => 'poupanca'])['id'];

        // 1. Frações por XLSX: aplicadas, a fatura rateia o total por elas.
        $xlsx = self::xlsx([['unidade', 'fracao'], ['101', '30%'], ['102', '0.7']]);
        $fractions = $this->imports->preview($this->admin, 'fractions', ['condominium_id' => $this->alpha], $this->upload($xlsx, 'fracoes.xlsx'));
        self::assertSame(0, $fractions['error_count']);
        $this->imports->apply($this->admin, $fractions['id']);
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type,
            'competence' => substr($due, 0, 7), 'due_on' => $due, 'method' => 'fraction', 'amount_cents' => '1.000,00'])['id']);
        $items = array_column($invoice['items'], null, 'unit_label');
        self::assertSame([30000, 70000], [(int)$items['101']['amount_cents'], (int)$items['102']['amount_cents']]);

        // 2. Boleto da 101 pago pelo retorno do banco.
        $charge = (new BankCharges($this->pdo))->issue($this->admin, (int)$items['101']['entry_id'], $bankA);
        (new BankReturns($this->pdo))->process($this->admin, $bankA, 'retorno.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'IMP-1', 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => 30000, 'occurred_on' => $today],
        ]));

        // 3. Inadimplente antigo da 102 por planilha entra na inadimplência.
        $old = date('d/m/Y', strtotime('-60 days'));
        $delinquents = $this->imports->preview($this->admin, 'delinquents', ['condominium_id' => $this->alpha],
            $this->upload("unidade;descricao;valor;competencia;vencimento;conta\n102;Taxa antiga;250,00;{$old};{$old};1.01\n", 'inadimplentes.csv'));
        self::assertSame(0, $delinquents['error_count']);
        $this->imports->apply($this->admin, $delinquents['id']);
        self::assertSame(25000, (new Agreements($this->pdo))->delinquency($this->admin, $this->alpha)['totals']['amount_cents']);

        // 4. Extrato OFX: a baixa e a transferência conciliam sozinhas; a despesa paga dias antes fica pendente e é conciliada à mão.
        (new EntryShortcuts($this->pdo))->transfer($this->admin, ['condominium_id' => $this->alpha, 'from_bank_account_id' => $bankA,
            'to_bank_account_id' => $bankB, 'amount_cents' => '100,00', 'transferred_on' => $today, 'description' => 'Fundo de reserva']);
        $expense = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $cleaning,
            'description' => 'Limpeza', 'amount_cents' => '89,90', 'competence' => date('Y-m', strtotime('-10 days')),
            'due_on' => date('Y-m-d', strtotime('-10 days')), 'paid_on' => date('Y-m-d', strtotime('-10 days')), 'bank_account_id' => $bankA])['id'];
        $day = date('Ymd');
        $ofx = "<OFX><BANKACCTFROM><BANKID>001<ACCTID>56789</BANKACCTFROM><BANKTRANLIST>"
            . "<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>{$day}<TRNAMT>300.00<FITID>X1<MEMO>BOLETO 101</STMTTRN>"
            . "<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>{$day}<TRNAMT>-100.00<FITID>X2<MEMO>TRANSF RESERVA</STMTTRN>"
            . "<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>{$day}<TRNAMT>-89.90<FITID>X3<MEMO>LIMPEZA</STMTTRN></BANKTRANLIST></OFX>";
        $statement = $this->imports->preview($this->admin, 'ofx', ['condominium_id' => $this->alpha, 'bank_account_id' => $bankA], $this->upload($ofx, 'extrato.ofx'));
        $applied = $this->imports->apply($this->admin, $statement['id']);
        self::assertSame(['lines' => 3, 'matched' => 2, 'unmatched' => 1, 'already' => 0], $applied['summary']['applied']);
        $lines = array_column($applied['lines'], null, 'fitid');
        self::assertSame((int)$items['101']['entry_id'], $lines['X1']['entry_id'], 'A baixa do retorno concilia com o crédito.');
        self::assertNotNull($lines['X2']['transfer_id'], 'A transferência concilia com o débito.');
        $pending = $this->imports->pendingLines($this->admin, $this->alpha)['data'];
        self::assertSame(['X3'], array_column($pending, 'fitid'));
        self::assertSame('matched', $this->imports->resolveLine($this->admin, $pending[0]['id'], 'match', $expense, 'Limpeza paga antes do extrato')['status']);

        // 5. Outro arquivo com um FITID já importado: só o novo entra.
        $next = str_replace(['<FITID>X2', '<FITID>X3'], ['<FITID>X4', '<FITID>X5'], $ofx);
        $next = str_replace(['-100.00', '-89.90'], ['5.00', '7.00'], $next);
        $again = $this->imports->apply($this->admin, $this->imports->preview($this->admin, 'ofx', ['condominium_id' => $this->alpha, 'bank_account_id' => $bankA],
            $this->upload($next, 'extrato-2.ofx'))['id']);
        self::assertSame(['lines' => 2, 'already' => 1], ['lines' => $again['summary']['applied']['lines'], 'already' => $again['summary']['applied']['already']]);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_bank_statement_lines WHERE bank_account_id = {$bankA} AND fitid = 'X1'")->fetchColumn());

        // 6. Conta bancária de outro condomínio não serve para o extrato do Alpha.
        $betaBank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->beta, 'name' => 'Beta', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        try {
            $this->imports->preview($this->admin, 'ofx', ['condominium_id' => $this->alpha, 'bank_account_id' => $betaBank], $this->upload($ofx . ' ', 'x.ofx'));
            self::fail('Aceitou conta bancária de outro condomínio.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        // 7. Usuário só do Beta não alcança nada do Alpha.
        $outsider = $this->user([$this->beta]);
        foreach ([
            fn () => $this->imports->preview($outsider, 'fractions', ['condominium_id' => $this->alpha], $this->upload("unidade;fracao\n101;1\n", 'f.csv')),
            fn () => $this->imports->find($outsider, (int)$statement['id']),
            fn () => $this->imports->apply($outsider, (int)$statement['id']),
            fn () => $this->imports->resolveLine($outsider, (int)$pending[0]['id'], 'ignore', null, 'Fora do escopo'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} alcançou dado de outro condomínio.");
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->imports->list($outsider)['total']);
        self::assertSame([], $this->imports->pendingLines($outsider)['data']);
    }

    private function upload(string $content, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    /** @param list<list<string>> $matrix */
    private static function xlsx(array $matrix): string
    {
        $rows = '';
        foreach ($matrix as $r => $cells) {
            $rows .= '<row r="' . ($r + 1) . '">';
            foreach ($cells as $c => $value) {
                $rows .= '<c r="' . chr(65 + $c) . ($r + 1) . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
            }
            $rows .= '</row>';
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
        $zip->close();
        $content = (string)file_get_contents($path);
        @unlink($path);
        return $content;
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
