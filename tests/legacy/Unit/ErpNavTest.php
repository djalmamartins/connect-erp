<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ErpNav;

/**
 * Menu novo e menu atual do ERP (ADR 0397): toda página aparece uma vez em cada menu, nenhum endereço muda
 * e o menu de antes continua igual ao catálogo.
 */
final class ErpNavTest extends TestCase
{
    private static function catalog(): array
    {
        return require __DIR__ . '/../../../container/erp/connect/v1/components/layout/catalog.php';
    }

    /** @return list<string> */
    private static function ids(array $nav): array
    {
        $ids = [];
        foreach ($nav as $group) {
            foreach ($group['items'] as [$id]) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function testEveryPageAppearsExactlyOnceInBothMenus(): void
    {
        $catalog = self::catalog();
        $all = array_keys($catalog['pages']);
        sort($all);
        foreach (ErpNav::MODES as $mode) {
            $ids = self::ids(ErpNav::build($catalog, $mode));
            sort($ids);
            self::assertSame($all, $ids, "menu {$mode}");
        }
    }

    public function testNewMenuHasAgendaGatehouseAndReadingsWithoutOperational(): void
    {
        $catalog = self::catalog();
        $nav = ErpNav::build($catalog, 'novo');
        self::assertArrayNotHasKey('operacional', $nav);
        self::assertSame(['Agenda', 'Portaria', 'Leituras'], [$nav['agenda']['label'], $nav['portaria']['label'], $nav['leituras']['label']]);
        $names = static fn (string $key): array => array_map(static fn (array $item): string => $catalog['pages'][$item[0]]['name'], $nav[$key]['items']);
        self::assertSame(['Chamados', 'Ocorrências', 'Manutenções', 'Vistorias', 'Reservas Áreas Comuns', 'Agendamentos Pendentes', 'Lista de espera', 'Agendamentos', 'Configurações', 'Seguros', 'Contratos'], $names('agenda'));
        self::assertSame(['Relógios', 'Leituras', 'Tarifas', 'Importação de Leituras'], $names('leituras'));
        self::assertCount(6, $nav['portaria']['items']);
        // Mudanças aparece como seção: "Agendamentos" e "Configurações" deixam de ficar sem contexto.
        self::assertSame(['AP-120', 'Mudanças'], $nav['agenda']['items'][7]);
        // Grupo só de menu não tem visão geral; os módulos do catálogo continuam com a deles.
        self::assertNull($nav['agenda']['hub']);
        self::assertSame('/financeiro', $nav['financeiro']['hub']);
        // Grupos novos cabem sem o 3º nível (a sidebar só recolhe seções acima de 14 páginas).
        foreach (['agenda', 'portaria', 'leituras'] as $key) {
            self::assertLessThanOrEqual(14, count($nav[$key]['items']), $key);
        }
    }

    public function testCurrentMenuIsTheCatalogAsBefore(): void
    {
        $catalog = self::catalog();
        $nav = ErpNav::build($catalog, 'atual');
        self::assertSame(array_keys($catalog['groups']), array_keys($nav));
        foreach ($catalog['pages'] as $id => $page) {
            self::assertSame($page['group'], ErpNav::groupOf($nav, $id), $id);
            self::assertSame($page['section'], ErpNav::sectionOf($nav, $id), $id);
        }
    }

    public function testModeComesFromCookieAndFallsBackToNew(): void
    {
        self::assertSame('novo', ErpNav::mode([]));
        self::assertSame('atual', ErpNav::mode([ErpNav::COOKIE => 'atual']));
        self::assertSame('novo', ErpNav::mode([ErpNav::COOKIE => '<script>']));
    }

    public function testRoutesDoNotChange(): void
    {
        // O menu não mexe no catálogo: as páginas movidas continuam com o endereço de /operacional.
        $catalog = self::catalog();
        self::assertSame('/operacional/reservas-areas-comuns', $catalog['pages']['AP-113']['route']);
        self::assertSame('/importacoes/importacao-de-leituras', $catalog['pages']['AP-044']['route']);
    }
}
