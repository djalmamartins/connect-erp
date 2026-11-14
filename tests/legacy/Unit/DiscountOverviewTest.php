<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DiscountOverview;

/**
 * ADR 0290: descontos concedidos em contas a receber, mês a mês, somente leitura. Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2) para a regra pura (janela de meses, faixas nos limites, linhas por mês com totais iguais à soma,
 * faixas somando os descontos, contas zeradas e maiores descontos com o corte), a montagem sem motivo nem quem deu o
 * desconto (fora da janela, outro condomínio, conta apagada e conta de outro condomínio de fora), a validação, o escopo e o
 * contrato. As faixas vêm de BANDS; nada de quantidade nem lista fixa. O MariaDB confere com descontos de verdade
 * (Integration\DiscountOverviewTest).
 */
final class DiscountOverviewTest extends TestCase
{
    private PDO $pdo;
    private DiscountOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, description TEXT, amount_cents INTEGER, deleted_at TEXT NULL)',
            'CREATE TABLE erp_entry_adjustments (id INTEGER PRIMARY KEY, condominium_id INTEGER, entry_id INTEGER, kind TEXT, amount_cents INTEGER, before_cents INTEGER,
                after_cents INTEGER, reason TEXT, created_by INTEGER, created_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $entry = $this->pdo->prepare("INSERT INTO erp_entries (id, condominium_id, unit_id, description, amount_cents, deleted_at) VALUES (?, ?, 5, 'Apto 101 do Fulano', 0, ?)");
        foreach ([[1, 1, null], [2, 1, null], [3, 1, null], [4, 1, '2026-11-02 10:00:00'], [5, 3, null], [6, 3, null]] as $row) {
            $entry->execute($row);
        }
        $adjustment = $this->pdo->prepare("INSERT INTO erp_entry_adjustments (id, condominium_id, entry_id, kind, amount_cents, before_cents, after_cents, reason, created_by,
            created_at) VALUES (?, ?, ?, 'discount', ?, ?, ?, 'Pedido do Fulano', 3, ?)");
        foreach ([[1, 1, 1, 100, 1000, 900, '2026-11-02 10:00:00'], [2, 1, 1, 450, 900, 450, '2026-11-05 10:00:00'], [3, 1, 2, 300, 300, 0, '2026-10-10 10:00:00'],
            [4, 1, 3, 200, 1000, 800, '2026-09-01 00:00:00'], [5, 1, 3, 50, 800, 750, '2026-08-31 23:59:59'], [6, 1, 4, 10, 100, 90, '2026-11-01 10:00:00'],
            [7, 3, 5, 10, 100, 90, '2026-11-01 10:00:00'], [8, 1, 6, 10, 100, 90, '2026-11-01 10:00:00']] as $row) {
            $adjustment->execute($row);
        }
        $this->service = new DiscountOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testMonthsAndBands(): void
    {
        self::assertSame(['2026-09', '2026-10', '2026-11'], DiscountOverview::months('2026-11-14', 3));
        self::assertSame(['2025-12', '2026-01'], DiscountOverview::months('2026-01-31', 2), 'Vira o ano.');
        self::assertCount(DiscountOverview::DEFAULT_MONTHS, DiscountOverview::months('2026-11-14', DiscountOverview::DEFAULT_MONTHS));
        $bands = array_keys(DiscountOverview::BANDS);
        foreach (DiscountOverview::BANDS as $band => $limit) {
            if ($limit !== null) {
                self::assertSame($band, DiscountOverview::band($limit, 100), "{$limit}% ainda é {$band}.");
                self::assertNotSame($band, DiscountOverview::band($limit * 10 + 1, 1000), "Passou de {$limit}%.");
            }
        }
        self::assertSame($bands[count($bands) - 1], DiscountOverview::band(100, 100), 'Zerar a conta é a última faixa.');
        self::assertSame($bands[0], DiscountOverview::band(1, 100000));
    }

    public function testSummaryFromTheCondominium(): void
    {
        $report = $this->service->build($this->user(), '1', '3', '2026-11-14');
        self::assertSame([1, 3, '2026-09', '2026-11'], [$report['condominium_id'], $report['months'], $report['from'], $report['to']]);
        self::assertSame(['2026-09', '2026-10', '2026-11'], array_column($report['rows'], 'month'), 'Todos os meses, sempre.');
        $pick = static fn (array $r): array => [$r['discounts'], $r['entries'], $r['discount_cents'], $r['before_cents']];
        self::assertSame([[1, 1, 200, 1000], [1, 1, 300, 300], [2, 1, 550, 1900]], array_map($pick, $report['rows']),
            'Sem o de fora da janela, o da conta apagada, o de outro condomínio e o de conta de outro condomínio.');
        foreach (['discounts', 'entries', 'discount_cents', 'before_cents'] as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        self::assertSame($report['totals']['discounts'], array_sum($report['by_band']), 'As faixas somam os descontos.');
        $bands = array_keys(DiscountOverview::BANDS);
        self::assertSame(array_combine($bands, [1, 2, 1]), $report['by_band']);
        self::assertSame([1, [2], false], [$report['zeroed'], $report['zeroed_entry_ids'], $report['zeroed_truncated']]);
        self::assertSame([[1, 2, 3], false], [$report['largest_entry_ids'], $report['largest_truncated']], 'Do maior desconto para o menor.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Apto', 'Pedido', 'reason', 'created_by', 'description', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $default = $this->service->build($this->user(), 1, null, '2026-11-14');
        self::assertSame([DiscountOverview::DEFAULT_MONTHS, '2025-12', 5], [$default['months'], $default['from'], $default['totals']['discounts']],
            'Doze meses entram o de agosto.');
    }

    public function testIdsAreCutAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= DiscountOverview::MAX_IDS + 1; $i++) {
            $many[] = ['entry_id' => $i, 'month' => '2026-11', 'discount_cents' => 100, 'before_cents' => 100, 'after_cents' => 0];
        }
        $report = DiscountOverview::summarize($many, ['2026-11']);
        self::assertCount(DiscountOverview::MAX_IDS, $report['zeroed_entry_ids']);
        self::assertTrue($report['zeroed_truncated']);
        self::assertTrue($report['largest_truncated']);
        self::assertSame(DiscountOverview::MAX_IDS + 1, $report['zeroed'], 'A contagem não é cortada.');
        $empty = DiscountOverview::summarize([], DiscountOverview::months('2026-11-14', 4));
        self::assertSame(4, count($empty['rows']), 'Sem desconto, os meses aparecem zerados.');
        self::assertSame(0, $empty['totals']['discounts']);
    }

    public function testValidationAndScope(): void
    {
        foreach ([[null, null], ['', null], [0, null], ['abc', null], [1, '0'], [1, 'x'], [1, (string)(DiscountOverview::MAX_MONTHS + 1)], [[], null]] as [$c, $m]) {
            try {
                $this->service->build($this->user(), $c, $m, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, null, '2026-11-14');
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0534_entry_shortcuts.sql');
        self::assertMatchesRegularExpression("/CREATE TABLE IF NOT EXISTS `erp_entry_adjustments`.*?`kind` enum\\('discount'\\)/s", $sql, 'A trilha guarda só descontos.');
        $shortcuts = (string)file_get_contents($root . '/source/Services/Erp/EntryShortcuts.php');
        self::assertStringContainsString("INSERT INTO erp_entry_adjustments (condominium_id, entry_id, kind, amount_cents, before_cents, after_cents", $shortcuts,
            'A trilha é gravada pelo desconto de uma conta.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/DiscountOverview.php');
        foreach (['$this->scope->assertAccess(', 'SELECT NOW()', "a.kind = 'discount'", 'e.deleted_at IS NULL', 'e.condominium_id = a.condominium_id'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'reason', 'created_by', 'description', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem motivo, quem deu, descrição ou unidade: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/DiscountOverview.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $trail = (string)file_get_contents($root . '/source/Controllers/Api/V1/EntryShortcuts.php');
        self::assertStringContainsString("ENTRIES = ['erp.entries.view'", $trail, 'A mesma permissão da trilha de descontos.');
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/discounts", "DiscountOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/extra-charges/cancel-batch"'), strpos($index, $route), 'Logo depois do cancelamento em lote.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /extra-charges/cancel-batch:\n"), strpos($openapi, "  /reports/discounts:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
