<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Services\Erp\AccountabilityBookDocument as BookDocument;
use Source\Services\Erp\AccountabilityDeadline as Deadline;
use Source\Services\Erp\LocalHolidays;

/** Feriados do estado e da cidade no prazo, PDF e pacote da prestação de contas (ADR 0306): regras puras e contratos. */
final class LocalHolidaysTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private function view(string $kind = 'monthly'): array
    {
        $item = static fn (int $seq, array $docs): array => ['seq' => $seq, 'paid_on' => '2026-10-12', 'description' => 'Água setembro', 'document_number' => 'NF 1',
            'amount_cents' => 32000, 'proof' => $docs !== [], 'documents' => $docs];
        $doc = static fn (int $id, string $kind, string $name): array => ['id' => $id, 'kind' => $kind, 'kind_label' => $kind === 'nota_fiscal' ? 'Nota fiscal' : 'Comprovante de pagamento',
            'name' => $name, 'mime' => 'application/pdf', 'size_bytes' => 10];
        $month = static fn (string $competence, array $items): array => ['competence' => $competence, 'from' => $competence . '-01', 'to' => $competence . '-31',
            'totals' => ['opening_cents' => 1000, 'in_cents' => 0, 'out_cents' => 32000, 'closing_cents' => -31000, 'banks_closing_cents' => -31000, 'reconciled' => true],
            'banks' => [], 'documents' => 2, 'missing_proof' => 0,
            'sections' => [['nature' => 'receita', 'label' => 'Receitas', 'total_cents' => 0, 'entries' => 0, 'categories' => []],
                ['nature' => 'despesa', 'label' => 'Despesas pagas no mês', 'total_cents' => 32000, 'entries' => 1, 'categories' => [
                    ['code' => '2.01', 'name' => 'Água', 'total_cents' => 32000, 'entries' => 1, 'groups' => [['name' => 'Copasa', 'total_cents' => 32000, 'items' => $items]]]]]]];
        $oct = $month('2026-10', [$item(1, [$doc(10, 'comprovante', 'Pix Copasa.png'), $doc(7, 'nota_fiscal', 'NF Copasa 1.pdf')])]);
        $nov = $month('2026-11', [$item(1, [$doc(11, 'nota_fiscal', 'nf.pdf'), $doc(7, 'nota_fiscal', 'NF Copasa 1.pdf')])]);
        $condo = ['name' => 'Residencial Lafayette', 'legal_name' => 'Condomínio Lafayette', 'document' => '12345678000199', 'address' => 'Rua A, 1'];
        $base = ['version' => 2, 'generated_at' => '2026-11-03 10:00:00', 'under_review' => false, 'condominium' => $condo];
        return $kind === 'annual'
            ? $base + ['kind' => 'annual', 'period' => '2026', 'book' => ['year' => '2026', 'totals' => ['opening_cents' => 1000, 'in_cents' => 0, 'out_cents' => 64000, 'closing_cents' => -63000, 'reconciled' => true],
                'categories' => [['nature' => 'despesa', 'code' => '2.01', 'name' => 'Água', 'total_cents' => 64000, 'entries' => 2]], 'documents' => 4, 'missing_proof' => 0, 'months' => [$oct, $nov]]]
            : $base + ['kind' => 'monthly', 'period' => '2026-10', 'book' => $oct];
    }

    public function testCityKeyStateAndDates(): void
    {
        self::assertSame('belo horizonte', LocalHolidays::cityKey('  Belo   Horizonte '));
        self::assertSame('sao joao del rei', LocalHolidays::cityKey('São João del-Rei'));
        self::assertSame('', LocalHolidays::cityKey(null));
        self::assertSame('MG', LocalHolidays::state(' mg '));
        self::assertNull(LocalHolidays::state('XX'));
        $rows = [['month' => 12, 'day' => 8, 'year' => 0], ['month' => 8, 'day' => 15, 'year' => 0], ['month' => 11, 'day' => 6, 'year' => 2026],
            ['month' => 2, 'day' => 29, 'year' => 0], ['month' => 12, 'day' => 8, 'year' => 0]];
        self::assertSame(['2026-08-15', '2026-11-06', '2026-12-08'], LocalHolidays::datesFor($rows, 2026), 'Só neste ano vale em 2026; 29/02 não existe em 2026.');
        self::assertSame(['2028-02-29', '2028-08-15', '2028-12-08'], LocalHolidays::datesFor($rows, 2028));
        self::assertSame(['month' => 11, 'day' => 6, 'year' => 2026], LocalHolidays::parseDate('2026-11-06'));
        self::assertSame(['month' => 11, 'day' => 6, 'year' => 2026], LocalHolidays::parseDate('06/11/2026'));
        foreach (['', '31/02/2026', '2026-13-01', 'amanhã', '01/01/1990'] as $bad) {
            try {
                LocalHolidays::parseDate($bad);
                self::fail("{$bad} deveria ser recusado.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $label = LocalHolidays::present(['id' => 1, 'state' => 'MG', 'city' => 'Belo Horizonte', 'city_key' => 'belo horizonte', 'month' => 12, 'day' => 8, 'year' => 0, 'name' => 'Imaculada Conceição']);
        self::assertSame(['city', '08/12', true, null], [$label['scope'], $label['label'], $label['every_year'], $label['year']]);
        $once = LocalHolidays::present(['id' => 2, 'state' => 'MG', 'city' => null, 'city_key' => '', 'month' => 11, 'day' => 6, 'year' => 2026, 'name' => 'Ponto facultativo']);
        self::assertSame(['state', '06/11/2026', false, 2026], [$once['scope'], $once['label'], $once['every_year'], $once['year']]);
    }

    public function testLocalHolidaysMoveTheFifthBusinessDay(): void
    {
        self::assertSame('2026-11-09', Deadline::businessDay('2026-11')->format('Y-m-d'));
        self::assertSame('2026-11-10', Deadline::businessDay('2026-11', 5, ['2026-11-06'])->format('Y-m-d'), 'Feriado municipal na sexta empurra o prazo.');
        self::assertSame('2026-11-09', Deadline::businessDay('2026-11', 5, ['2026-11-07'])->format('Y-m-d'), 'Feriado no sábado não muda nada.');
        self::assertFalse(Deadline::isBusinessDay(new DateTimeImmutable('2026-11-06'), ['2026-11-06']));
        self::assertTrue(Deadline::isBusinessDay(new DateTimeImmutable('2026-11-06')));
        self::assertSame(1, Deadline::businessDaysUntil(new DateTimeImmutable('2026-11-05'), new DateTimeImmutable('2026-11-09'), ['2026-11-06']));
        $deadline = Deadline::businessDay('2026-11', 5, ['2026-11-06']);
        self::assertSame('waiting', Deadline::situation(new DateTimeImmutable('2026-11-04'), $deadline, false, false, ['2026-11-06']));
        self::assertSame('due_soon', Deadline::situation(new DateTimeImmutable('2026-11-05'), $deadline, false, false, ['2026-11-06']));
        self::assertSame('due_soon', Deadline::situation(new DateTimeImmutable('2026-11-10'), $deadline, false, false, ['2026-11-06']), 'No dia 10 o prazo local ainda não venceu.');
    }

    public function testPdfFileNamesAndPackageOrder(): void
    {
        self::assertSame('prestacao-de-contas-residencial-lafayette-2026-10.pdf', BookDocument::filename($this->view()));
        self::assertSame('livro-anual-residencial-lafayette-2026.zip', BookDocument::filename($this->view('annual'), 'zip'));
        self::assertSame([10 => 'documentos/001-comprovante-pix-copasa.png', 7 => 'documentos/002-nota-fiscal-nf-copasa-1.pdf'], BookDocument::packagePaths($this->view()));
        self::assertSame([10 => 'documentos/10-outubro/001-comprovante-pix-copasa.png', 7 => 'documentos/10-outubro/002-nota-fiscal-nf-copasa-1.pdf',
            11 => 'documentos/11-novembro/001-nota-fiscal-nf.pdf'], BookDocument::packagePaths($this->view('annual')), 'O mesmo documento entra uma vez só.');
    }

    public function testPdfRendersMonthlyAndAnnualWithDocumentLinks(): void
    {
        $theme = dirname(__DIR__, 3) . '/container/erp/connect/v1';
        $document = new BookDocument($theme);
        $base = 'https://erp.connect.lab/prestacao/' . str_repeat('a', 32);
        $html = $document->html($this->view(), $base);
        self::assertStringContainsString('href="' . $base . '/documento/10"', $html, 'Cada documento leva ao link público.');
        self::assertStringContainsString('Prestação de contas de outubro de 2026', $html);
        self::assertDoesNotMatchRegularExpression('~src="https?://~', $html, 'Nada de fora no PDF.');
        $annual = $document->html($this->view('annual'), $base);
        self::assertStringContainsString('Livro anual de 2026', $annual);
        self::assertSame(2, substr_count($annual, 'class="month"'), 'Cada mês numa página.');
        $pdf = $document->pdf($this->view('annual'), $base);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString($base . '/documento/11', $pdf, 'O link do documento fica clicável no PDF.');
    }

    public function testRoutesSecurityAndMigration(): void
    {
        $index = $this->file('index.php');
        self::assertStringContainsString('$route->get("/prestacao/{token}/pdf", "AccountabilityLinkPublic:pdf");', $index);
        self::assertLessThan(strpos($index, 'if (erp_host()) {'), strpos($index, '"/prestacao/{token}/pdf"'), 'O PDF responde em qualquer host, como o link.');
        foreach (['$route->get("/local-holidays", "LocalHolidays:index"', '$route->post("/local-holidays", "LocalHolidays:store"', '$route->delete("/local-holidays/{id}", "LocalHolidays:destroy"'] as $route) {
            self::assertStringContainsString($route, $index);
        }
        $public = $this->file('source/Controllers/Web/Connect/AccountabilityLinkPublic.php');
        self::assertStringContainsString("request_limit('accountability_link_pdf', self::PDF_LIMIT, self::WINDOW)", $public);
        self::assertStringContainsString("hash('sha256', \$token . '|' . \$view['sha256'])", $public, 'O nome do PDF guardado não revela o token.');
        $routes = $this->file('container/erp/connect/v1/routes.php');
        foreach (['"/prestacao-de-contas/livro-caixa/link/{id}/pacote", "CashBookScreens:packageLink"', '"/prestacao-de-contas/livro-caixa/feriados", "CashBookScreens:addHoliday"',
            '"/prestacao-de-contas/livro-caixa/feriados/{id}/remover", "CashBookScreens:removeHoliday"'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        $screens = $this->file('source/Controllers/Erp/Connect/CashBookScreens.php');
        self::assertStringContainsString("\$this->linkAction(\$data, static function (AccountabilityLinks \$links, User \$user, int \$condominium) use (\$data): string {\n            \$holiday = (new LocalHolidays())->add(", $screens,
            'Incluir feriado passa pelo guarda com CSRF e erp.closing.manage.');
        $api = $this->file('source/Controllers/Api/V1/LocalHolidays.php');
        self::assertStringContainsString("(\$manage && !Access::can('erp.closing.manage', \$user))", $api);
        $migration = $this->file('database/migrations/20261114_2100_local_holidays.sql');
        self::assertStringContainsString('UNIQUE KEY `uq_erp_local_holidays_day` (`state`, `city_key`, `year`, `month`, `day`)', $migration);
        self::assertStringNotContainsString('tenant_id', $migration);
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261114_2100_local_holidays.down.sql');
        self::assertStringContainsString('LocalHolidays::datesFor($rows', $this->file('source/Services/Erp/AccountabilityDeadline.php'), 'O cron usa os feriados do lugar.');
    }
}
