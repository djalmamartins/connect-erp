<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CashBook;
use Source\Services\Erp\CashBookDocument;
use Source\Services\Erp\EntryAttachments;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VirtualDisk;

/** Anexos dos lançamentos e livro caixa mensal com PDF e pacote (ADR 0174, CN-007). */
final class CashBookIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private EntryAttachments $attachments;
    private string $root;
    private string $work;
    private int $fee;
    private int $water;
    private int $bank;
    private int $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Residencial Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->root = sys_get_temp_dir() . '/cb-root-' . bin2hex(random_bytes(4));
        $this->work = sys_get_temp_dir() . '/cb-work-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0700, true);
        $this->attachments = new EntryAttachments($this->pdo, null, new VirtualDisk($this->pdo, null, $this->root, true));
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa condominial'])['id'];
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '077', 'branch' => '0001',
            'account_number' => '1234-5', 'kind' => 'corrente', 'opening_balance_cents' => '1.000,00', 'opened_on' => '2026-01-01'])['id'];
        $this->savings = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '077', 'branch' => '0001',
            'account_number' => '9999-0', 'kind' => 'poupanca', 'opening_balance_cents' => '500,00', 'opened_on' => '2026-01-01'])['id'];
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->work] as $dir) {
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    public function testAttachReuseRefuseAndScope(): void
    {
        $first = $this->entry($this->alpha, 'despesa', $this->water, '300,00', '2026-11', '2026-11-12', 'Conta de água 1/2');
        $second = $this->entry($this->alpha, 'despesa', $this->water, '300,00', '2026-11', '2026-11-20', 'Conta de água 2/2');
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

        $nota = $this->attachments->attach($this->admin, $first, $this->file('NF 123.pdf', $pdf), 'nota_fiscal');
        self::assertSame(['nota_fiscal', 'Nota fiscal', 'NF 123.pdf'], [$nota['kind'], $nota['kind_label'], $nota['original_name']]);
        self::assertArrayNotHasKey('path', $nota, 'A API nunca mostra o caminho na pasta privada.');
        $folder = $this->pdo->query('SELECT folder FROM erp_documents WHERE id = ' . $nota['document_id'])->fetchColumn();
        self::assertSame('notas_fiscais', $folder);

        // A mesma nota nas duas parcelas: reaproveita o arquivo (uma cópia só).
        $again = $this->attachments->attach($this->admin, $second, $this->file('NF 123 copia.pdf', $pdf), 'nota_fiscal');
        self::assertSame($nota['document_id'], $again['document_id']);
        try {
            $this->attachments->attach($this->admin, $first, $this->file('de novo.pdf', $pdf), 'nota_fiscal');
            self::fail('Aceitou o mesmo arquivo duas vezes no mesmo lançamento.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        $proof = $this->attachments->attach($this->admin, $first, $this->file('comprovante.png', $this->png()), 'comprovante');
        self::assertSame('comprovantes', $this->pdo->query('SELECT folder FROM erp_documents WHERE id = ' . $proof['document_id'])->fetchColumn());
        self::assertSame(['nota_fiscal', 'comprovante'], array_column($this->attachments->list($this->admin, $first), 'kind'));
        self::assertSame(hash('sha256', $pdf), hash_file('sha256', $this->attachments->file($this->admin, $first, $nota['id'])['path']));

        foreach ([['gaveta', 'NF.pdf', $pdf], ['nota_fiscal', 'nota.pdf', "<?php echo 1;"]] as [$kind, $name, $content]) {
            try {
                $this->attachments->attach($this->admin, $first, $this->file($name, $content), $kind);
                self::fail('Aceitou tipo ou arquivo inválido.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Fora do escopo: o lançamento "não existe" para quem só vê a Beta.
        $limited = $this->user([$this->beta]);
        foreach ([fn () => $this->attachments->list($limited, $first), fn () => $this->attachments->file($limited, $first, $nota['id']),
            fn () => $this->attachments->attach($limited, $first, $this->file('x.pdf', $pdf . ' '), 'outro')] as $call) {
            try {
                $call();
                self::fail('Viu ou alterou lançamento de outro condomínio.');
            } catch (RegistryNotFound) {
                self::addToAssertionCount(1);
            }
        }
        // Anexo de outro lançamento não abre pelo id errado.
        $this->expectException(RegistryNotFound::class);
        $this->attachments->file($this->admin, $second, $proof['id']);
    }

    public function testRemoveIsBlockedOnClosedCompetence(): void
    {
        $entry = $this->entry($this->alpha, 'despesa', $this->water, '120,00', '2026-10', '2026-10-05', 'Manutenção');
        $item = $this->attachments->attach($this->admin, $entry, $this->file('recibo.pdf', "%PDF-1.4\n%%EOF\n"), 'recibo');
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-10', 'closed')")->execute([$this->alpha]);

        // Mês fechado: anexar ainda vale (o comprovante chega depois); remover não.
        $late = $this->attachments->attach($this->admin, $entry, $this->file('comprovante.pdf', "%PDF-1.4\n% pago\n%%EOF\n"), 'comprovante');
        try {
            $this->attachments->remove($this->admin, $entry, $item['id']);
            self::fail('Removeu anexo de competência fechada.');
        } catch (DomainException $error) {
            self::assertStringContainsString('2026-10', $error->getMessage());
        }
        $this->pdo->exec("UPDATE erp_month_closings SET status = 'reopened'");
        $this->attachments->remove($this->admin, $entry, $late['id']);
        self::assertSame(['recibo'], array_column($this->attachments->list($this->admin, $entry), 'kind'));
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_documents WHERE deleted_at IS NULL AND folder = \'comprovantes\'')->fetchColumn(),
            'O comprovante removido continua no Disco Virtual, ao lado do recibo.');
    }

    public function testCashBookBalancesNumbersAnnexesAndReconciles(): void
    {
        $this->entry($this->alpha, 'receita', $this->fee, '400,00', '2026-10', '2026-10-10', 'Taxa de outubro');     // antes: entra no saldo anterior
        $fee = $this->entry($this->alpha, 'receita', $this->fee, '800,00', '2026-11', '2026-11-05', 'Taxa de novembro');
        $water = $this->entry($this->alpha, 'despesa', $this->water, '300,00', '2026-11', '2026-11-12', 'Conta de água');
        $this->entry($this->alpha, 'despesa', $this->water, '50,00', '2026-11', '2026-11-28', 'Tarifa bancária');
        $this->entry($this->alpha, 'despesa', $this->water, '999,00', '2026-11', null, 'Em aberto');                      // não pago: fora
        $this->entry($this->alpha, 'despesa', $this->water, '70,00', '2026-11', '2026-12-01', 'Pago em dezembro');       // fora do mês
        $this->entry($this->beta, 'receita', $this->fee, '5.000,00', '2026-11', null, 'Outro condomínio');
        // Transferência entre contas do condomínio: não muda o caixa.
        $this->pdo->prepare("INSERT INTO erp_bank_transfers (condominium_id, from_bank_account_id, to_bank_account_id, amount_cents, transferred_on, description)
            VALUES (?, ?, ?, 20000, '2026-11-15', 'Para a reserva')")->execute([$this->alpha, $this->bank, $this->savings]);
        $this->attachments->attach($this->admin, $water, $this->file('nota-agua.pdf', "%PDF-1.4\n% agua\n%%EOF\n"), 'nota_fiscal');
        $this->attachments->attach($this->admin, $water, $this->file('pix.png', $this->png()), 'comprovante');
        $this->attachments->attach($this->admin, $fee, $this->file('extrato.pdf', "%PDF-1.4\n% extrato\n%%EOF\n"), 'outro');

        $book = (new CashBook($this->pdo))->build($this->admin, $this->alpha, '2026-11');
        self::assertSame(['2026-11-01', '2026-11-30', false], [$book['from'], $book['to'], $book['closed']]);
        self::assertSame([190000, 80000, 35000, 235000], [$book['opening_cents'], $book['in_cents'], $book['out_cents'], $book['closing_cents']],
            'Saldo anterior = 1.000 + 500 de saldo inicial + 400 recebidos em outubro.');
        self::assertTrue($book['reconciled']);
        self::assertSame(235000, $book['banks_closing_cents']);
        self::assertSame(['Taxa de novembro', 'Conta de água', 'Tarifa bancária'], array_column($book['lines'], 'description'));
        self::assertSame([270000, 240000, 235000], array_column($book['lines'], 'balance_cents'));
        self::assertSame([['A1'], ['A2', 'A3'], []], array_column($book['lines'], 'annex_refs'), 'Anexos numerados na ordem do livro (nota antes do comprovante).');
        self::assertSame(['outro', 'nota_fiscal', 'comprovante'], array_column($book['annexes'], 'kind'));
        self::assertArrayNotHasKey('path', $book['annexes'][0]);
        self::assertSame(['Tarifa bancária'], array_column($book['missing_proof'], 'description'), 'Receita sem anexo não é pendência; despesa sem nota/comprovante é.');
        $banks = array_column($book['banks'], null, 'name');
        self::assertSame([20000, 20000], [$banks['Movimento']['transfers_out_cents'], $banks['Reserva']['transfers_in_cents']]);
        self::assertSame(['1.01', '2.01'], array_column($book['accounts'], 'code'));
        self::assertSame('livro-caixa-residencial-alpha-2026-11.pdf', CashBook::filename($book));

        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-11', 'closed')")->execute([$this->alpha]);
        self::assertTrue((new CashBook($this->pdo))->build($this->admin, $this->alpha, '2026-11')['closed']);

        // Pago sem conta bancária (gravado direto, fora da regra): o livro mostra a diferença.
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, 'despesa', ?, 'Sem banco', 2500, '2026-11', '2026-11-20', '2026-11-20')")->execute([$this->alpha, $this->water]);
        $off = (new CashBook($this->pdo))->build($this->admin, $this->alpha, '2026-11');
        self::assertFalse($off['reconciled']);
        self::assertSame(-2500, $off['difference_cents']);

        try {
            (new CashBook($this->pdo))->build($this->admin, $this->alpha, '2026-13');
            self::fail('Aceitou mês inválido.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(CondominiumAccessDenied::class);
        (new CashBook($this->pdo))->build($this->user([$this->beta]), $this->alpha, '2026-11');
    }

    public function testPdfAndPackageCarryTheAnnexes(): void
    {
        $water = $this->entry($this->alpha, 'despesa', $this->water, '300,00', '2026-11', '2026-11-12', 'Conta de água');
        $this->attachments->attach($this->admin, $water, $this->file('nota-agua.pdf', "%PDF-1.4\n% agua\n%%EOF\n"), 'nota_fiscal');
        $this->attachments->attach($this->admin, $water, $this->file('pix.png', $this->png()), 'comprovante');

        $document = new CashBookDocument($this->attachments, dirname(__DIR__, 3) . '/container/erp/connect/v1');
        $book = $document->prepare((new CashBook($this->pdo))->build($this->admin, $this->alpha, '2026-11', true));
        self::assertSame(['package', 'embed'], array_column($book['annexes'], 'placement'), 'Imagem entra no PDF; PDF vai no pacote.');

        $html = $document->html($book);
        self::assertStringContainsString('LIVRO CAIXA', $html);
        self::assertStringContainsString('Residencial Alpha', $html);
        self::assertStringContainsString('anexos/A01-nota-fiscal-nota-agua.pdf', $html);
        self::assertStringNotContainsString($this->root, $html, 'O caminho na pasta privada nunca vai para o documento.');

        $pdf = $document->pdf($book);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(3000, strlen($pdf));

        $zipPath = $document->package($book);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        self::assertSame(['livro-caixa-residencial-alpha-2026-11.pdf', 'anexos/A01-nota-fiscal-nota-agua.pdf', 'anexos/A02-comprovante-pix.png'], $names);
        self::assertSame("%PDF-1.4\n% agua\n%%EOF\n", $zip->getFromName('anexos/A01-nota-fiscal-nota-agua.pdf'));
        $zip->close();
        unlink($zipPath);
    }

    private function entry(int $condominium, string $nature, int $account, string $amount, string $competence, ?string $paidOn, string $description): int
    {
        $input = ['condominium_id' => $condominium, 'nature' => $nature, 'chart_account_id' => $account, 'amount_cents' => $amount,
            'competence' => $competence, 'description' => $description, 'due_on' => $competence . '-10'];
        if ($paidOn !== null) {
            $input += ['paid_on' => $paidOn, 'bank_account_id' => $this->bank];
        }
        return $this->service->save($this->admin, 'entries', $input)['id'];
    }

    /** @return array{name:string, tmp_name:string, error:int} */
    private function file(string $name, string $content): array
    {
        $path = $this->work . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK];
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 30, 120, 80));
        ob_start();
        imagepng($image);
        imagedestroy($image);
        return (string)ob_get_clean();
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
