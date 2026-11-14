<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ExtraChargeOverview;

/**
 * ADR 0288: situação das cobranças extras e multas avulsas, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2) para a regra pura (recebida, em aberto, vencida e em acordo nos limites do dia; cancelada sem acordo de
 * fora), as linhas por tipo com totais iguais à soma, a cobrança cancelada contada à parte e sem valores, outro
 * condomínio, conta apagada e conta sem cobrança de fora, os ids das vencidas, nenhuma descrição, motivo nem unidade, a
 * validação, o escopo e o contrato. Os tipos vêm de EntryShortcuts::CHARGE_KINDS e as situações de STATES; nada de
 * quantidade nem lista fixa. O MariaDB confere com cobranças de verdade (Integration\ExtraChargeOverviewTest).
 */
final class ExtraChargeOverviewTest extends TestCase
{
    private PDO $pdo;
    private ExtraChargeOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_extra_charges (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, description TEXT, reason TEXT, status TEXT, created_by INTEGER)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, description TEXT, amount_cents INTEGER, status TEXT, paid_on TEXT,
                due_on TEXT, agreement_id INTEGER NULL, extra_charge_id INTEGER NULL, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b] = self::kinds();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $charge = $this->pdo->prepare("INSERT INTO erp_extra_charges (id, condominium_id, kind, description, reason, status, created_by)
            VALUES (?, ?, ?, 'Pintura da fachada do Fulano', 'Barulho do Fulano', ?, 7)");
        foreach ([[1, 1, $a, 'active'], [2, 1, $a, 'cancelled'], [3, 1, $b, 'active'], [4, 3, $a, 'active']] as $row) {
            $charge->execute($row);
        }
        $entry = $this->pdo->prepare("INSERT INTO erp_entries (id, condominium_id, unit_id, description, amount_cents, status, paid_on, due_on, agreement_id, extra_charge_id,
            deleted_at) VALUES (?, ?, 5, 'Apto 101 do Fulano', ?, ?, ?, ?, ?, ?, ?)");
        foreach ([[1, 1, 1000, 'active', '2026-11-05', '2026-11-10', null, 1, null], [2, 1, 2000, 'active', null, '2026-11-01', null, 1, null],
            [3, 1, 3000, 'active', null, '2026-11-14', null, 1, null], [4, 1, 4000, 'inactive', null, '2026-10-01', 9, 1, null],
            [5, 1, 5000, 'inactive', null, '2026-10-01', null, 1, null], [6, 1, 6000, 'inactive', null, '2026-10-01', null, 2, null],
            [7, 1, 700, 'active', null, '2026-10-20', null, 3, null], [8, 1, 800, 'active', '2026-10-30', '2026-11-30', null, 3, null],
            [9, 3, 900, 'active', null, '2026-10-01', null, 4, null], [10, 1, 1100, 'active', null, '2026-09-01', null, 1, '2026-10-02 10:00:00'],
            [11, 1, 1200, 'active', null, '2026-09-01', null, null, null], [12, 1, 1300, 'active', null, '2026-09-01', null, 4, null]] as $row) {
            $entry->execute($row);
        }
        $this->service = new ExtraChargeOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} o primeiro e o último tipo, vindos de EntryShortcuts::CHARGE_KINDS */
    private static function kinds(): array
    {
        $kinds = array_keys(EntryShortcuts::CHARGE_KINDS);
        self::assertGreaterThanOrEqual(2, count($kinds));
        return [$kinds[0], $kinds[count($kinds) - 1]];
    }

    public function testStateAtTheDayLimits(): void
    {
        $today = '2026-11-14';
        self::assertSame('paid', ExtraChargeOverview::state('active', '2026-11-01', '2026-10-01', false, $today), 'Baixada vale antes do vencimento.');
        self::assertSame('open', ExtraChargeOverview::state('active', null, $today, false, $today), 'Vence hoje: ainda em aberto.');
        self::assertSame('overdue', ExtraChargeOverview::state('active', null, '2026-11-13', false, $today));
        self::assertSame('agreement', ExtraChargeOverview::state('inactive', null, '2026-10-01', true, $today));
        self::assertNull(ExtraChargeOverview::state('inactive', null, '2026-10-01', false, $today), 'Cancelada sem acordo fica de fora.');
        foreach (['paid', 'open', 'overdue', 'agreement'] as $state) {
            self::assertArrayHasKey($state, ExtraChargeOverview::STATES);
        }
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$a, $b] = self::kinds();
        $report = $this->service->build($this->user(), '1', '2026-11-14');
        self::assertSame(1, $report['condominium_id']);
        self::assertSame(array_keys(EntryShortcuts::CHARGE_KINDS), array_column($report['rows'], 'kind'), 'Todos os tipos, sempre.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        $pick = static fn (array $r): array => [$r['charges'], $r['cancelled'], $r['entries'], $r['amount_cents'], $r['paid'], $r['paid_cents'], $r['open'],
            $r['open_cents'], $r['overdue'], $r['overdue_cents'], $r['agreement'], $r['agreement_cents'], $r['oldest_overdue_on']];
        self::assertSame([1, 1, 4, 10000, 1, 1000, 1, 3000, 1, 2000, 1, 4000, '2026-11-01'], $pick($rows[$a]),
            'Sem a cancelada sem acordo, a da cobrança cancelada, a apagada e a de outro condomínio.');
        self::assertSame([1, 0, 2, 1500, 1, 800, 0, 0, 1, 700, 0, 0, '2026-10-20'], $pick($rows[$b]));
        foreach (array_keys($report['totals']) as $key) {
            if ($key !== 'oldest_overdue_on') {
                self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
            }
        }
        foreach ($report['rows'] as $row) {
            self::assertSame($row['entries'], array_sum(array_intersect_key($row, ExtraChargeOverview::STATES)), 'As situações somam as contas.');
            $cents = 0;
            foreach (array_keys(ExtraChargeOverview::STATES) as $state) {
                $cents += $row[$state . '_cents'];
            }
            self::assertSame($row['amount_cents'], $cents, 'Os valores por situação somam o lançado.');
        }
        self::assertSame('2026-10-20', $report['totals']['oldest_overdue_on']);
        self::assertSame([[3, 1], false], [$report['overdue_ids'], $report['overdue_truncated']], 'Da cobrança que venceu antes.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Pintura', 'Barulho', 'Apto', 'description', 'reason', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCutAndEmpty(): void
    {
        $charges = [];
        $entries = [];
        for ($i = 1; $i <= ExtraChargeOverview::MAX_IDS + 1; $i++) {
            $charges[] = ['id' => $i, 'kind' => self::kinds()[0], 'status' => 'active'];
            $entries[] = ['charge_id' => $i, 'amount_cents' => 100, 'status' => 'active', 'paid_on' => null, 'due_on' => '2026-11-01', 'in_agreement' => false];
        }
        $report = ExtraChargeOverview::summarize($charges, $entries, '2026-11-14');
        self::assertCount(ExtraChargeOverview::MAX_IDS, $report['overdue_ids']);
        self::assertTrue($report['overdue_truncated']);
        self::assertSame(ExtraChargeOverview::MAX_IDS + 1, $report['totals']['overdue'], 'A contagem não é cortada.');
        $empty = ExtraChargeOverview::summarize([], [], '2026-11-14');
        self::assertSame(count(EntryShortcuts::CHARGE_KINDS), count($empty['rows']), 'Sem cobrança, as linhas aparecem zeradas.');
        self::assertNull($empty['totals']['oldest_overdue_on']);
    }

    public function testValidationAndScope(): void
    {
        foreach ([null, '', 0, 'abc', '-1', []] as $value) {
            try {
                $this->service->build($this->user(), $value, '2026-11-14');
                self::fail('Condomínio inválido passou: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, '2026-11-14');
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0534_entry_shortcuts.sql');
        preg_match('/CREATE TABLE IF NOT EXISTS `erp_extra_charges`.*?`kind` enum\(([^)]*)\)/s', $sql, $kind);
        self::assertSame(array_keys(EntryShortcuts::CHARGE_KINDS), array_map('trim', explode(',', str_replace("'", '', $kind[1]))), 'Os tipos batem com a enum.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/ExtraChargeOverview.php');
        foreach (['$this->scope->assertAccess(', 'EntryShortcuts::CHARGE_KINDS', 'SELECT NOW()', 'e.deleted_at IS NULL', 'x.condominium_id = e.condominium_id'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'description', 'reason', 'unit_id', 'created_by', 'cancel_reason'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem descrição, motivo, unidade ou quem lançou: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ExtraChargeOverview.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.invoices.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $shortcuts = (string)file_get_contents($root . '/source/Controllers/Api/V1/EntryShortcuts.php');
        self::assertStringContainsString("INVOICES = ['erp.invoices.view'", $shortcuts, 'A mesma permissão da lista de cobranças extras.');
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/extra-charges", "ExtraChargeOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/banners/deactivate-ended"'), strpos($index, $route), 'Logo depois da inativação de banners.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /banners/deactivate-ended:\n"), strpos($openapi, "  /reports/extra-charges:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
