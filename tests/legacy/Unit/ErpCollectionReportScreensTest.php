<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\CollectionReportScreens as Screens;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\CollectionReports;

/**
 * Telas dos relatórios de cobrança (AP-047, AP-048, AP-057 a AP-061, AP-071 e AP-072; ADR 0081 e 0077):
 * tela própria só com dados, permissões iguais às da API, colunas iguais às do CSV e o endereço do CSV.
 */
final class ErpCollectionReportScreensTest extends TestCase
{
    public function testPagesPermissionsAndTemplate(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (Screens::PAGES as $page => [$kind]) {
            self::assertSame(Screens::permissions($page)[0], $permissions[$page]);
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame('pages/reports/collection-report', Panel::ownTemplate($page, ['cReportReady' => true]));
        }
        self::assertSame(['erp.agreements.view', 'erp.residents.view'], Screens::permissions('AP-061'));
        self::assertSame(CollectionReports::DELINQUENT_VIEWS, array_values(array_filter(array_map(static fn (array $p): ?string => $p[0] === 'delinquents' ? $p[1] : null, Screens::PAGES))));
    }

    public function testColumnsMatchTheCsvExport(): void
    {
        foreach (Screens::PAGES as [$kind, $variant]) {
            $variants = $kind === 'receipts' && $variant !== 'entry' ? array_keys(Screens::ACCOUNT_GROUPS) : [$variant];
            foreach ($variants as $v) {
                $csv = CollectionReports::csvTable($kind . ($v !== null ? '-' . $v : ''), ['rows' => []])['header'];
                $screen = array_column(Screens::columns($kind, $v), 0);
                self::assertNotSame([], $screen);
                self::assertLessThanOrEqual(count($csv), count($screen), "{$kind} {$v}: a tela não mostra mais colunas que o CSV");
            }
        }
    }

    public function testCsvUrlAndDates(): void
    {
        $filters = ['de' => '2026-01-01', 'ate' => '2026-03-31', 'referencia' => '2026-11-09', 'situacao' => 'breached', 'agrupar' => null];
        self::assertSame('/api/v1/reports/agreements?format=csv&condominium_id=4&from=2026-01-01&to=2026-03-31&status=breached', Screens::csvUrl('agreements', null, $filters, 4));
        self::assertSame('/api/v1/reports/delinquents?format=csv&as_of=2026-11-09&view=resident', Screens::csvUrl('delinquents', 'resident', $filters, null));
        self::assertSame('/api/v1/reports/receipts?format=csv&from=2026-01-01&to=2026-03-31&group=chart_account', Screens::csvUrl('receipts', 'chart_account', $filters, null));
        self::assertSame('2026-02-28', Screens::date('2026-02-28'));
        self::assertNull(Screens::date('2026-02-30'));
        self::assertNull(Screens::date(['x']));
        self::assertSame('Ana', Screens::value(['responsible' => ['name' => 'Ana']], 'responsible.name'));
        self::assertNull(Screens::value(['responsible' => null], 'responsible.name'));
    }
}
