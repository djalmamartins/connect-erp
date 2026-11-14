<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\CashBookScreens;
use Source\Controllers\Erp\Connect\EntryAttachmentScreens;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\CashBook;
use Source\Services\Erp\CashBookDocument;
use Source\Services\Erp\EntryAttachments;
use Source\Services\Erp\VirtualDisk;

/** Livro caixa (CN-007) e anexos dos lançamentos (ADR 0174): regras puras, rotas, telas e permissões. */
final class ErpCashBookTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testComposeRunsTheBalanceAndNumbersAnnexesInBookOrder(): void
    {
        $book = CashBook::compose(10000, [
            ['nature' => 'receita', 'amount_cents' => 5000, 'attachments' => [['kind' => 'outro']]],
            ['nature' => 'despesa', 'amount_cents' => 2000, 'attachments' => [['kind' => 'nota_fiscal'], ['kind' => 'comprovante']]],
            ['nature' => 'despesa', 'amount_cents' => 20000],
        ]);
        self::assertSame([15000, 13000, -7000], array_column($book['lines'], 'balance_cents'), 'Saldo negativo aparece, não é escondido.');
        self::assertSame([[5000, 0], [0, 2000], [0, 20000]], array_map(static fn (array $l): array => [$l['in_cents'], $l['out_cents']], $book['lines']));
        self::assertSame([['A1'], ['A2', 'A3'], []], array_column($book['lines'], 'annex_refs'));
        self::assertSame([[1, 1], [2, 2], [3, 2]], array_map(static fn (array $a): array => [$a['number'], $a['line']], $book['annexes']));
        self::assertSame([5000, 22000, -7000], [$book['in_cents'], $book['out_cents'], $book['closing_cents']]);
        self::assertSame(['lines' => [], 'annexes' => [], 'in_cents' => 0, 'out_cents' => 0, 'closing_cents' => 300], CashBook::compose(300, []));
    }

    public function testMissingProofIsOnlyExpenseWithoutInvoiceReceiptOrProof(): void
    {
        $lines = CashBook::compose(0, [
            ['nature' => 'despesa', 'amount_cents' => 100, 'paid_on' => '2026-11-01', 'description' => 'Com nota', 'attachments' => [['kind' => 'nota_fiscal']]],
            ['nature' => 'despesa', 'amount_cents' => 200, 'paid_on' => '2026-11-02', 'description' => 'Só outro', 'attachments' => [['kind' => 'outro']]],
            ['nature' => 'despesa', 'amount_cents' => 300, 'paid_on' => '2026-11-03', 'description' => 'Recibo', 'attachments' => [['kind' => 'recibo']]],
            ['nature' => 'receita', 'amount_cents' => 400, 'paid_on' => '2026-11-04', 'description' => 'Receita'],
        ])['lines'];
        self::assertSame(['Só outro'], array_column(CashBook::missingProof($lines), 'description'));
    }

    public function testPeriodAndFilename(): void
    {
        self::assertSame(['2026-02-01', '2026-02-28'], CashBook::period('2026-02'));
        self::assertSame(['2028-02-01', '2028-02-29'], CashBook::period('2028-02'));
        self::assertSame('livro-caixa-edificio-lafayette-2026-11.zip', CashBook::filename(['condominium' => ['name' => 'Edifício Lafayette'], 'competence' => '2026-11'], 'zip'));
        self::assertSame('livro-caixa-condominio-2026-11.pdf', CashBook::filename(['condominium' => ['name' => '***'], 'competence' => '2026-11']));
        $this->expectException(InvalidArgumentException::class);
        CashBook::period('2026-13');
    }

    public function testDocumentPlacementsAndPackageNames(): void
    {
        $annexes = CashBookDocument::placements([
            ['mime' => 'image/png', 'scan_status' => 'clean', 'exists' => true],
            ['mime' => 'application/pdf', 'scan_status' => 'unscanned', 'exists' => true],
            ['mime' => 'image/jpeg', 'scan_status' => 'infected', 'exists' => true],
            ['mime' => 'image/jpeg', 'scan_status' => 'clean', 'exists' => false],
            ['mime' => 'image/webp', 'scan_status' => 'clean', 'exists' => true],
        ], 1);
        self::assertSame(['embed', 'package', 'blocked', 'missing', 'package'], array_column($annexes, 'placement'), 'Além do limite, a imagem vai só no pacote.');
        self::assertSame('anexos/A07-nota-fiscal-nf-123-agua-e-esgoto.pdf', CashBookDocument::packageName(['number' => 7, 'kind' => 'nota_fiscal', 'original_name' => 'NF 123 (água e esgoto).pdf']));
        self::assertSame('anexos/A12-comprovante.png', CashBookDocument::packageName(['number' => 12, 'kind' => 'comprovante', 'original_name' => '___.png']));
        self::assertSame('novembro de 2026', CashBookDocument::monthLabel('2026-11'));
        self::assertSame('março de 2027', CashBookDocument::monthLabel('2027-03'));
    }

    public function testAttachmentKindsFoldersAndUploadTypes(): void
    {
        self::assertSame(['nota_fiscal', 'conta', 'comprovante', 'recibo', 'outro'], array_keys(EntryAttachments::KINDS), 'Conta ou fatura entrou no ADR 0301.');
        foreach (EntryAttachments::FOLDER as $kind => $folder) {
            self::assertArrayHasKey($kind, EntryAttachments::KINDS);
            self::assertArrayHasKey($folder, VirtualDisk::FOLDERS, 'Cada tipo cai numa pasta do Disco Virtual.');
        }
        self::assertSame('nota_fiscal', EntryAttachments::kind('nota_fiscal'));
        self::assertTrue(VirtualDisk::accepts('text/xml', 'xml'), 'XML da NF-e é aceito.');
        self::assertFalse(VirtualDisk::accepts('text/html', 'html'));
        $migration = $this->file('database/migrations/20261111_0711_entry_attachments.sql');
        self::assertStringContainsString("'comprovantes'", $migration);
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261111_0711_entry_attachments.down.sql');
        $this->expectException(InvalidArgumentException::class);
        EntryAttachments::kind('boleto');
    }

    public function testScreensRoutesAndPermissions(): void
    {
        self::assertSame('erp.entries.view', Panel::pagePermissions()['CN-007']);
        self::assertNull(Panel::ownTemplate('CN-007', ['cashReady' => false]));
        self::assertSame('pages/reports/cash-book', Panel::ownTemplate('CN-007', ['cashReady' => true]));
        self::assertSame('2026-10', CashBookScreens::month('2026-10', '2026-11'));
        self::assertSame('2026-11', CashBookScreens::month('2026-11', '2026-11'), 'O mês atual vale (prévia).');
        self::assertSame('2026-10', CashBookScreens::month('2026-12', '2026-11'), 'Mês futuro volta para o anterior.');
        self::assertSame('2025-12', CashBookScreens::month(null, '2026-01'), 'Sem mês, abre o mês anterior.');
        self::assertSame('/financeiro/contas-a-pagar/9', EntryAttachmentScreens::back('/financeiro/contas-a-pagar', 9));
        self::assertSame('/financeiro/lancamentos/9', EntryAttachmentScreens::back('https://evil.example/', 9), 'Retorno só para as telas de lançamento.');

        $routes = $this->file('container/erp/connect/v1/routes.php');
        foreach (['"/financeiro/lancamentos/{id}/anexos", "EntryAttachmentScreens:store"', '"/financeiro/lancamentos/{id}/anexos/{attachment}/excluir", "EntryAttachmentScreens:remove"',
            '"/prestacao-de-contas/livro-caixa/pdf", "CashBookScreens:pdf"', '"/prestacao-de-contas/livro-caixa/pacote", "CashBookScreens:package"'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        $card = $this->file('container/erp/connect/v1/components/cards/entry-attachments.php');
        self::assertStringContainsString('enctype="multipart/form-data"', $card);
        self::assertSame(2, substr_count($card, '<?= csrf_input() ?>'), 'Enviar e remover levam o CSRF.');
        self::assertMatchesRegularExpression('/<\?php if \(\$attachments\[\'canManage\'\]\): \?>\s*<form class="erp-form"/', $card, 'Só quem gerencia vê o envio.');
        $controller = $this->file('source/Controllers/Erp/Connect/EntryAttachmentScreens.php');
        self::assertSame(2, substr_count($controller, 'csrf_verify($data)'));
        self::assertStringContainsString("'erp.entries.manage'", $controller);
    }
}
