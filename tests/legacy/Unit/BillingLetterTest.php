<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\BillingLetter;

/** Carta de cobrança impressa (ADR 0320 e 0319): regras puras de linha digitável, código de barras, instruções, resumo e encaixe. */
final class BillingLetterTest extends TestCase
{
    /** Código de barras e linha digitável válidos (Febraban) do mesmo título fictício, banco 077. */
    private const BARCODE = '07792164600000650000001112220333334444444444';
    private const LINE = '07790001161222033333544444444440216460000065000';

    public function testFormatsDigitableLineAndKeepsOtherTextAsIs(): void
    {
        self::assertSame('07790.00116 12220.333335 44444.444440 2 16460000065000', BillingLetter::formatDigitable(self::LINE));
        self::assertSame('07790.00116 12220.333335 44444.444440 2 16460000065000', BillingLetter::formatDigitable('07790.00116 12220.333335 44444.444440 2 16460000065000'));
        self::assertSame('SANDBOX 123', BillingLetter::formatDigitable(' SANDBOX 123 '));
        self::assertSame('', BillingLetter::formatDigitable(null));
    }

    public function testItfBarcodeHasGuardsAndFiveBarsAndSpacesPerDigitPair(): void
    {
        $widths = BillingLetter::itfWidths(self::BARCODE);
        // início (4) + 22 pares × 10 elementos + fim (3)
        self::assertCount(4 + 22 * 10 + 3, $widths);
        self::assertSame([1, 1, 1, 1], array_slice($widths, 0, 4));
        self::assertSame([3, 1, 1], array_slice($widths, -3));
        // Cada par tem exatamente 2 barras largas e 2 espaços largos.
        for ($pair = 0; $pair < 22; $pair++) {
            $chunk = array_slice($widths, 4 + $pair * 10, 10);
            self::assertSame(2, count(array_filter($chunk, static fn (int $w, int $i): bool => $i % 2 === 0 && $w === 3, ARRAY_FILTER_USE_BOTH)));
            self::assertSame(2, count(array_filter($chunk, static fn (int $w, int $i): bool => $i % 2 === 1 && $w === 3, ARRAY_FILTER_USE_BOTH)));
        }
        // "07": 0 = barras 00110, 7 = espaços 00011
        self::assertSame([1, 1, 1, 1, 3, 1, 3, 3, 1, 3], array_slice($widths, 4, 10));
        self::assertSame([], BillingLetter::itfWidths('123'));
        self::assertSame([], BillingLetter::itfWidths('SANDBOX12'));
        self::assertSame('', BillingLetter::barcodeSvg('SANDBOX12'));
        self::assertStringStartsWith('data:image/svg+xml;base64,', BillingLetter::barcodeSvg(self::BARCODE));
    }

    public function testInstructionsFromBillingRules(): void
    {
        self::assertSame(['Após o vencimento cobrar multa de 2% e juros de 1% ao mês, proporcional aos dias de atraso.',
            'Não receber em cheque. Em caso de dúvida, fale com o Financeiro da Connect.'], BillingLetter::instructions(200, 100));
        self::assertSame('Após o vencimento cobrar multa de 2,5%.', BillingLetter::instructions(250, 0)[0]);
        self::assertCount(1, BillingLetter::instructions(0, 0));
    }

    public function testSummaryCountsQuotasByAmount(): void
    {
        $items = static fn (array $amounts): array => array_map(static fn (int $a): array => ['paid_on' => '2026-10-10', 'description' => 'x', 'amount_cents' => $a], $amounts);
        $demo = ['result_cents' => 300, 'receita' => ['total_cents' => 3910, 'groups' => [['name' => 'RECEITAS', 'total_cents' => 3910, 'accounts' => [
            ['name' => 'Taxa condominial', 'total_cents' => 3250, 'items' => $items([650, 650, 650, 325, 650, 325])],
            ['name' => 'Rendimento', 'total_cents' => 160, 'items' => $items([160])],
        ]]]], 'despesa' => ['total_cents' => 1300, 'groups' => [['name' => 'DESPESAS', 'total_cents' => 1300, 'accounts' => [
            ['name' => 'Energia', 'total_cents' => 1300, 'items' => $items([1000, 300])],
        ]]]]];
        $sum = BillingLetter::summary($demo);
        self::assertSame([['count' => 4, 'amount_cents' => 650, 'total_cents' => 2600], ['count' => 2, 'amount_cents' => 325, 'total_cents' => 650]],
            $sum['receita']['groups'][0]['accounts'][0]['quotas']);
        self::assertSame([], $sum['receita']['groups'][0]['accounts'][1]['quotas'], 'Um recebimento só: sem cotas.');
        self::assertSame([], $sum['despesa']['groups'][0]['accounts'][0]['quotas'], 'Despesa não vira cota.');
        self::assertSame(300, $sum['result_cents']);
    }

    public function testFitShrinksTheBiggerSideAndNeverNeedsAThirdPage(): void
    {
        $section = static fn (int $groups, int $accounts, int $quotas): array => ['total_cents' => 0, 'groups' => array_fill(0, $groups, ['name' => 'G', 'total_cents' => 0,
            'accounts' => array_fill(0, $accounts, ['name' => 'A', 'total_cents' => 0, 'quotas' => array_fill(0, $quotas, ['count' => 2, 'amount_cents' => 1, 'total_cents' => 2])])])];
        $sum = static fn (array $r, array $d): array => ['receita' => $section(...$r), 'despesa' => $section(...$d), 'result_cents' => 0];

        self::assertSame(['receita' => 'quotas', 'despesa' => 'accounts'], BillingLetter::fit($sum([1, 2, 2], [1, 3, 0])));
        self::assertSame(['receita' => 'accounts', 'despesa' => 'accounts'], BillingLetter::fit($sum([2, 2, 3], [1, 3, 0])));
        self::assertSame(['receita' => 'quotas', 'despesa' => 'groups'], BillingLetter::fit($sum([1, 3, 0], [4, 5, 0])), 'Sem cotas, o lado maior (despesas) encolhe.');
        self::assertSame(['receita' => 'totals', 'despesa' => 'totals'], BillingLetter::fit($sum([40, 9, 2], [40, 9, 0])));
    }

    public function testBlocksFromFreeText(): void
    {
        self::assertSame([
            ['kind' => 'title', 'text' => 'Financeiro'], ['kind' => 'highlight', 'text' => '31 93618-0510'], ['kind' => 'small', 'text' => 'Seg a sex'],
            ['kind' => 'item', 'text' => 'boletos'], ['kind' => 'text', 'text' => 'Site: www.connect.com.br'],
        ], BillingLetter::blocks("# Financeiro\r\n! 31 93618-0510\n~ Seg a sex\n\n  - boletos\nSite: www.connect.com.br\n"));
        self::assertSame([], BillingLetter::blocks(''));
    }

    public function testSmallRules(): void
    {
        self::assertSame("a\n\nb", \Source\Services\Erp\ErpSettings::normalize('letter.notice_body', "a  \r\n\r\n\r\nb\x07"), 'Texto de várias linhas mantém a quebra.');
        self::assertSame('2025-12', BillingLetter::previousCompetence('2026-01'));
        self::assertSame('222.555.888-46', BillingLetter::cpfCnpj('22255588846'));
        self::assertSame('08.922.856/0001-26', BillingLetter::cpfCnpj('08922856000126'));
        self::assertSame('', BillingLetter::cpfCnpj(''));
        self::assertSame('carta-de-cobranca-residencial-aurora-bloco-10-103-2026-11.pdf',
            BillingLetter::filename(['condominium' => ['name' => 'Residencial Aurora'], 'unit' => ['label' => 'Bloco 10 - 103'], 'competence' => '2026-11']));
    }
}
