<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AccountabilityDocument;
use Source\Services\Erp\CashBookDocument;
use Source\Services\Erp\EntryAttachments;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VirtualDisk;

/** Prestação de contas no padrão do PDF de outubro (revisão de 15/11 itens 19 e 21, ADR 0314). */
final class AccountabilityDocumentTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private string $root;
    private int $bank;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare("INSERT INTO condominiums (name, legal_name, document, street, number, city, state, district, status)
            VALUES ('Bloco 10', 'Condomínio do Bloco 10', '08922856000126', 'Av Rotariano', '10', 'Conselheiro Lafaiete', 'MG', 'CAMPO ALEGRE', 'active')")->execute();
        $this->alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->admin = (new User())->findById($id);
        $this->service = new RegistryService($this->pdo);
        $this->root = sys_get_temp_dir() . '/ad-root-' . bin2hex(random_bytes(4));
        foreach ([['1', 'receita', 'sintetica', 'Receitas mensais'], ['1.01', 'receita', 'analitica', 'Taxa de condomínio'], ['2', 'despesa', 'sintetica', 'Despesas de consumo'],
            ['2.01', 'despesa', 'analitica', 'Água e esgoto'], ['2.02', 'despesa', 'analitica', 'Energia elétrica']] as [$code, $nature, $kind, $name]) {
            $this->pdo->prepare('INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES (?, ?, ?, ?)')->execute([$code, $name, $nature, $kind]);
        }
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Banco conta corrente', 'bank_code' => '077', 'branch' => '0001',
            'account_number' => '1234-5', 'kind' => 'corrente', 'opening_balance_cents' => '1.000,00', 'opened_on' => '2026-01-01'])['id'];
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        foreach ([['sindico', 'Ana Síndica'], ['fiscal', 'Bruno Conselheiro'], ['consultivo', 'Carla Conselheira']] as [$role, $name]) {
            $this->pdo->prepare("INSERT INTO erp_board_members (condominium_id, role, name, term_starts_on, term_ends_on, status) VALUES (?, ?, ?, '2026-01-01', '2027-12-31', 'active')")->execute([$this->alpha, $role, $name]);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testDocumentInTheOctoberModelWithHiddenUnitAndProofs(): void
    {
        $account = fn (string $code): int => (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '{$code}'")->fetchColumn();
        $this->entry('receita', $account('1.01'), '2.600,00', '2026-10-02', 'Taxa de condomínio', $this->unit);
        $water = $this->entry('despesa', $account('2.01'), '1.966,03', '2026-10-13', 'Copasa');
        $this->entry('despesa', $account('2.02'), '42,19', '2026-10-05', 'Cemig');
        $this->entry('despesa', $account('2.02'), '99,00', '2026-11-05', 'Cemig de novembro');
        $attachments = new EntryAttachments($this->pdo, null, new VirtualDisk($this->pdo, null, $this->root, true));
        $tmp = (string)tempnam(sys_get_temp_dir(), 'png');
        $image = imagecreatetruecolor(40, 30);
        imagepng($image, $tmp);
        $attachments->attach($this->admin, $water, ['name' => 'conta-copasa.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], 'conta');

        // Colunas Sobras de rateio e Fundo de reserva (ADR 0320): lançamentos patrimoniais ligados à despesa.
        $patrimonial = function (string $code, string $nature): int {
            $id = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '{$code}' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
            if ($id > 0) {
                return $id;
            }
            $this->pdo->prepare("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES (?, ?, ?, 'analitica')")->execute([$code, 'Conta ' . $code, $nature]);
            return (int)$this->pdo->lastInsertId();
        };
        [$reserve, $surplus, $result] = [$patrimonial('8.2', 'liability'), $patrimonial('9.1', 'equity'), $patrimonial('9.2', 'equity')];
        $use = $this->pdo->prepare("INSERT INTO erp_patrimonial_entries (condominium_id, entry_date, description, amount_cents, debit_account_id, credit_account_id, linked_entry_id)
            VALUES (?, '2026-10-13', 'Uso', ?, ?, ?, ?)");
        $use->execute([$this->alpha, 50000, $reserve, $result, $water]);
        $use->execute([$this->alpha, 10000, $surplus, $result, $water]);
        $use->execute([$this->alpha, 7000, $reserve, $result, $water]);
        $use->execute([$this->alpha, 7000, $result, $reserve, $water]);   // estorno: anula o de 70,00

        $theme = dirname(__DIR__, 3) . '/container/erp/connect/v1';
        $service = new AccountabilityDocument($this->pdo, null, $theme, new CashBookDocument($attachments, $theme));
        $doc = $service->build($this->admin, $this->alpha, '2026-10');
        self::assertSame('Bloco 10 (Conselheiro Lafaiete / CAMPO ALEGRE)', $doc['condominium']['label']);
        self::assertSame('1 de out. de 2026 - 31 de out. de 2026', $doc['period']);
        self::assertSame(['Ana Síndica', ['Bruno Conselheiro', 'Carla Conselheira']], [$doc['board']['sindico'], $doc['board']['council']]);
        $demo = $doc['demonstrative'];
        self::assertSame([260000, 200822, 59178], [$demo['receita']['total_cents'], $demo['despesa']['total_cents'], $demo['result_cents']]);
        self::assertSame(['RECEITAS MENSAIS', 'Taxa de condomínio'], [$demo['receita']['groups'][0]['name'], $demo['receita']['groups'][0]['accounts'][0]['name']]);
        self::assertSame('Taxa de condomínio - Unidade 101', $demo['receita']['groups'][0]['accounts'][0]['items'][0]['description']);
        self::assertSame(['Água e esgoto', 'Energia elétrica'], array_column($demo['despesa']['groups'][0]['accounts'], 'name'));
        self::assertSame(['Cemig', 'Copasa'], array_column($doc['expenses'], 'description'), 'Despesas pagas no mês, pela data.');
        self::assertCount(1, $doc['expenses'][1]['annexes']);
        self::assertSame([[0, 0], [10000, 50000]], array_map(static fn (array $l): array => [$l['surplus_cents'], $l['reserve_cents']], $doc['expenses']));
        self::assertSame([10000, 50000], [$doc['surplus_total_cents'], $doc['reserve_total_cents']]);

        $hidden = $service->build($this->admin, $this->alpha, '2026-10', true);
        self::assertSame('Taxa de condomínio', $hidden['demonstrative']['receita']['groups'][0]['accounts'][0]['items'][0]['description'], 'Ocultar unidade.');
        self::assertStringNotContainsString('Unidade 101', $service->html($hidden));

        $html = $service->html($doc);
        foreach (['PRESTAÇÃO DE CONTAS', 'TERMO DE ABERTURA', 'Condomínio do Bloco 10', '08.922.856/0001-26', 'PARECER SOBRE PRESTAÇÃO DE CONTAS', 'Síndico(a): Ana Síndica',
            'Conselheiro(a): Bruno Conselheiro', 'Relatório demonstrativo financeiro de contas de 01/10/2026 até 31/10/2026', 'Total Grupo', '2.008,22', 'RESUMO DAS CONTAS',
            'Banco conta corrente', 'Relatório de lançamentos de 01/10/2026 até 31/10/2026', 'Sobras de Rateio (R$)', 'Fundo de Reserva (R$)', '500,00', 'conta-copasa.png', 'CNPJ: 43.346.552/0001-69'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        self::assertStringNotContainsString('Cemig de novembro', $html);
        $pdfDoc = $service->build($this->admin, $this->alpha, '2026-10', false, true);
        self::assertSame('embed', $pdfDoc['expenses'][1]['annexes'][0]['placement']);
        self::assertStringContainsString('data:image/', $service->html($pdfDoc, true), 'Comprovante dentro do PDF.');
        self::assertStringStartsWith('%PDF-', $service->pdf($pdfDoc));
        self::assertSame('prestacao-de-contas-bloco-10-2026-10.pdf', AccountabilityDocument::filename($doc));

        $months = $service->months($this->admin, $this->alpha, '2026-11');
        self::assertCount(AccountabilityDocument::MONTHS, $months);
        self::assertSame(['2026-11', 1], [$months[0]['competence'], $months[0]['payments']]);
        self::assertSame(['2026-10', 3, 'open'], [$months[1]['competence'], $months[1]['payments'], $months[1]['status']]);

        $outsider = $this->createUser(['level' => 2]);
        $this->expectException(CondominiumAccessDenied::class);
        $service->months((new User())->findById($outsider), $this->alpha, '2026-11');
    }

    private function entry(string $nature, int $account, string $amount, string $paidOn, string $description, ?int $unit = null): int
    {
        return $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => $nature, 'chart_account_id' => $account, 'amount_cents' => $amount,
            'competence' => substr($paidOn, 0, 7), 'description' => $description, 'due_on' => $paidOn, 'paid_on' => $paidOn, 'bank_account_id' => $this->bank]
            + ($unit !== null ? ['unit_id' => $unit] : []))['id'];
    }
}
