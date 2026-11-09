<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\CollectionReports;

/** Relatórios de cobrança (ADR 0081): período, visões, agrupamento e CSV seguro. */
final class CollectionReportsTest extends TestCase
{
    private function base(): array
    {
        $entry = static fn (int $id, int $cents, int $days, int $charges): array => ['id' => $id, 'description' => "Taxa {$id}", 'competence' => '2026-09',
            'due_on' => '2026-09-10', 'amount_cents' => $cents, 'days_late' => $days, 'bucket' => '31-60', 'charges' => ['charges_cents' => $charges]];
        return ['as_of' => '2026-11-09', 'totals' => ['units' => 2], 'units' => [
            ['condominium_id' => 1, 'condominium_name' => 'Alpha', 'unit_id' => 10, 'unit_label' => '101', 'entries' => [$entry(1, 50000, 60, 1500), $entry(2, 30000, 30, 600)],
                'amount_cents' => 80000, 'charges_cents' => 2100, 'total_cents' => 82100, 'oldest_due_on' => '2026-09-10', 'max_days_late' => 60],
            ['condominium_id' => 1, 'condominium_name' => 'Alpha', 'unit_id' => 11, 'unit_label' => '102', 'entries' => [$entry(3, 20000, 10, 100)],
                'amount_cents' => 20000, 'charges_cents' => 100, 'total_cents' => 20100, 'oldest_due_on' => '2026-10-30', 'max_days_late' => 10],
        ]];
    }

    public function testPeriodAndMask(): void
    {
        self::assertSame(['2026-01-01', '2026-12-31'], CollectionReports::period('2026-01-01', '2026-12-31'));
        foreach ([['2026-02-01', '2026-01-01'], ['2026-01-01', '2027-02-01'], ['01/01/2026', '2026-02-01']] as [$from, $to]) {
            try {
                CollectionReports::period($from, $to);
                self::fail("Aceitou {$from} a {$to}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('*********01', CollectionReports::maskDocument('123.456.789-01'));
        self::assertSame('', CollectionReports::maskDocument(null));
    }

    public function testDelinquentViews(): void
    {
        $base = $this->base();
        $general = CollectionReports::delinquentView($base, 'general');
        self::assertSame([['condominium_id' => 1, 'condominium_name' => 'Alpha', 'units' => 2, 'entries' => 3, 'amount_cents' => 100000,
            'charges_cents' => 2200, 'total_cents' => 102200, 'max_days_late' => 60]], $general, 'AP-057: por condomínio.');
        self::assertSame(['101', '102'], array_column(CollectionReports::delinquentView($base, 'unit'), 'unit_label'), 'AP-058: por unidade.');
        $detailed = CollectionReports::delinquentView($base, 'detailed');
        self::assertSame([3, 51500], [count($detailed), $detailed[0]['total_cents']], 'AP-059: uma linha por conta, valor + encargos.');
        $fee = CollectionReports::delinquentView($base, 'fee', [1 => '1.01 Taxa', 2 => '1.02 Fundo', 3 => '1.01 Taxa']);
        self::assertSame([['1.01 Taxa', 2, 2, 70000], ['1.02 Fundo', 1, 1, 30000]],
            array_map(static fn (array $r): array => [$r['account'], $r['units'], $r['entries'], $r['amount_cents']], $fee), 'AP-060: por taxa (conta do plano).');
        $resident = CollectionReports::delinquentView($base, 'resident', [], [10 => ['name' => 'Bia', 'document' => '*********01']]);
        self::assertSame(['Bia', null], [$resident[0]['responsible']['name'], $resident[1]['responsible']], 'AP-061: responsável da unidade, ou vazio.');
    }

    public function testFeeSummaryIgnoresCancelledAndGroupReceipts(): void
    {
        $summary = CollectionReports::feeSummary([
            ['condominium_id' => 1, 'condominium_name' => 'Alpha', 'status' => 'active', 'original_cents' => 100000, 'fees_cents' => 5000, 'discount_cents' => 2000, 'total_cents' => 103000],
            ['condominium_id' => 1, 'condominium_name' => 'Alpha', 'status' => 'cancelled', 'original_cents' => 9000, 'fees_cents' => 900, 'discount_cents' => 0, 'total_cents' => 9900],
        ]);
        self::assertSame(['agreements' => 1, 'cancelled' => 1, 'original_cents' => 100000, 'fees_cents' => 5000, 'discount_cents' => 2000, 'net_fees_cents' => 3000, 'total_cents' => 103000], $summary['totals']);

        $rows = [['condominium_id' => 1, 'condominium_name' => 'Alpha', 'bank_account' => 'Movimento', 'chart_account' => '1.01 Taxa', 'amount_cents' => 500, 'days_late' => 0],
            ['condominium_id' => 1, 'condominium_name' => 'Alpha', 'bank_account' => 'Movimento', 'chart_account' => '1.02 Fundo', 'amount_cents' => 300, 'days_late' => 4]];
        self::assertSame([['condominium_id' => 1, 'condominium_name' => 'Alpha', 'account' => 'Movimento', 'entries' => 2, 'amount_cents' => 800, 'late' => 1]],
            CollectionReports::groupReceipts($rows, 'bank_account'), 'AP-072: por conta bancária.');
        self::assertCount(2, CollectionReports::groupReceipts($rows, 'chart_account'));
        self::assertSame($rows, CollectionReports::groupReceipts($rows, 'entry'));
    }

    public function testCsvIsSafeAgainstFormulaInjection(): void
    {
        foreach (['=HYPERLINK("x")', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $danger) {
            self::assertSame("'" . $danger, CollectionReports::csvCell($danger), "Prefixa {$danger}");
        }
        self::assertSame('Taxa 101', CollectionReports::csvCell('Taxa 101'));
        $base = $this->base();
        $base['units'][0]['unit_label'] = '=cmd|calc';
        $table = CollectionReports::csvTable('delinquents-unit', ['rows' => CollectionReports::delinquentView($base, 'unit')]);
        self::assertSame(['Condomínio', 'Unidade', 'Contas', 'Valor', 'Encargos', 'Total', 'Mais antiga', 'Maior atraso (dias)'], $table['header']);
        self::assertSame(['Alpha', "'=cmd|calc", '2', '800,00', '21,00', '821,00', '10/09/2026', '60'], $table['rows'][0]);
        $csv = CollectionReports::csv($table);
        self::assertStringStartsWith("\xEF\xBB\xBFCondomínio;Unidade;", $csv);
        self::assertStringContainsString("\r\nAlpha;'=cmd|calc;2;800,00;", $csv);
        $this->expectException(InvalidArgumentException::class);
        CollectionReports::csvTable('desconhecido', []);
    }
}
