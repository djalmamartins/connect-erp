<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringPortfolio;

/**
 * ADR 0272: carteira de lançamentos fixos numa competência, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2) para a regra pura (as cinco situações com as bordas, a geração da competência, a atenção, totais iguais à
 * soma), a montagem sem descrição (excluídos e de outro condomínio de fora; lançamento de outra competência ou excluído não
 * conta como gerado; vínculo inativo só nos ativos), a validação, o escopo e o contrato; os cadastros são conferidos no
 * MariaDB (Integration\RecurringPortfolioTest).
 */
final class RecurringPortfolioTest extends TestCase
{
    private PDO $pdo;
    private RecurringPortfolio $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)', 'CREATE TABLE erp_cost_centers (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)', 'CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_recurring_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, nature TEXT, chart_account_id INTEGER, cost_center_id INTEGER,
                bank_account_id INTEGER, supplier_id INTEGER, description TEXT, amount_cents INTEGER, day_of_month INTEGER, starts_competence TEXT, ends_competence TEXT,
                notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, recurring_entry_id INTEGER, competence TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new RecurringPortfolio($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testClassifyAndSummarizePureRules(): void
    {
        $t = static fn (int $id, string $status, string $starts, ?string $ends, string $nature = 'despesa', int $cents = 100): array => ['id' => $id, 'status' => $status,
            'nature' => $nature, 'amount_cents' => $cents, 'starts_competence' => $starts, 'ends_competence' => $ends];
        $c = '2026-11';
        self::assertSame('2027-01', RecurringPortfolio::addMonths($c, 2));
        self::assertSame('running', RecurringPortfolio::classify($t(1, 'active', '2026-01', null), $c));
        self::assertSame('running', RecurringPortfolio::classify($t(1, 'active', '2026-11', '2027-02'), $c), 'Começa no mês e termina depois da janela.');
        self::assertSame('ending_soon', RecurringPortfolio::classify($t(1, 'active', '2026-01', '2027-01'), $c), 'Borda da janela.');
        self::assertSame('ending_soon', RecurringPortfolio::classify($t(1, 'active', '2026-01', '2026-11'), $c), 'Termina no próprio mês.');
        self::assertSame('ended_active', RecurringPortfolio::classify($t(1, 'active', '2026-01', '2026-10'), $c));
        self::assertSame('future', RecurringPortfolio::classify($t(1, 'active', '2026-12', null), $c));
        self::assertSame('inactive', RecurringPortfolio::classify($t(1, 'inactive', '2026-12', null), $c));

        $summary = RecurringPortfolio::summarize([$t(1, 'active', '2026-01', null, 'receita', 500), $t(2, 'active', '2026-01', '2026-12', 'despesa', 300),
            $t(3, 'active', '2026-01', '2026-01'), $t(4, 'inactive', '2026-01', null)], $c, [1 => true], [2 => true, 4 => true]);
        self::assertSame([[1, 500, 0], [1, 0, 300], [1, 0, 100], [0, 0, 0], [1, 0, 100]],
            array_map(static fn (array $r): array => [$r['templates'], $r['receita_cents'], $r['despesa_cents']], $summary['rows']));
        foreach (['templates', 'receita_cents', 'despesa_cents'] as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame(['in_force' => 2, 'generated' => 1, 'pending' => 1], $summary['generation']);
        self::assertSame(['inactive_link' => 1, 'without_end' => 1], $summary['attention'], 'Vínculo inativo só conta nos ativos.');
        $empty = RecurringPortfolio::summarize([], $c, [], []);
        self::assertSame([count(RecurringPortfolio::SITUATIONS), 0, 0], [count($empty['rows']), $empty['totals']['templates'], $empty['generation']['in_force']]);
    }

    public function testBuildWithoutDescriptions(): void
    {
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, status, deleted_at) VALUES (5, 'active', NULL), (6, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_suppliers (id, status, deleted_at) VALUES (9, 'active', NULL), (8, 'inactive', NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_recurring_entries (id, condominium_id, nature, chart_account_id, supplier_id, description, amount_cents, day_of_month,
            starts_competence, ends_competence, notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 10, ?, ?, ?, ?, ?)');
        foreach ([[1, 1, 'despesa', 5, 9, 10000, '2026-01', null, 'active', null], [2, 1, 'receita', 5, null, 5000, '2026-01', '2027-06', 'active', null],
            [3, 1, 'despesa', 5, null, 2000, '2026-01', '2027-01', 'active', null], [4, 1, 'despesa', 5, null, 3000, '2026-01', '2026-11', 'active', null],
            [5, 1, 'despesa', 5, null, 4000, '2026-01', '2026-10', 'active', null], [6, 1, 'despesa', 5, null, 1000, '2026-12', null, 'active', null],
            [7, 1, 'receita', 5, null, 7000, '2027-01', null, 'inactive', null], [8, 1, 'despesa', 5, null, 99900, '2026-01', null, 'active', '2026-01-01 00:00:00'],
            [9, 3, 'despesa', 5, null, 88800, '2026-01', null, 'active', null], [10, 1, 'despesa', 6, null, 1500, '2026-01', null, 'active', null],
            [11, 1, 'despesa', 5, 8, 800, '2026-01', null, 'inactive', null], [12, 1, 'despesa', 5, 8, 600, '2026-01', '2026-09', 'active', null]]
            as [$id, $c, $nature, $chart, $supplier, $cents, $starts, $ends, $status, $deleted]) {
            $insert->execute([$id, $c, $nature, $chart, $supplier, 'Portaria Fulano Ltda', $cents, $starts, $ends, 'Contrato 123', $status, $deleted]);
        }
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, recurring_entry_id, competence, deleted_at) VALUES (1, 1, '2026-11', NULL), (1, 3, '2026-11', NULL),
            (1, 2, '2026-10', NULL), (1, 4, '2026-11', '2026-11-02 00:00:00'), (3, 10, '2026-11', NULL), (1, NULL, '2026-11', NULL)");
        $report = $this->service->build($this->user(), '1', '2026-11');
        self::assertSame(['2026-11', '2027-01'], [$report['competence'], $report['soon_until']]);
        self::assertSame(['running' => [3, 5000, 11500], 'ending_soon' => [2, 0, 5000], 'ended_active' => [2, 0, 4600], 'future' => [1, 0, 1000], 'inactive' => [2, 7000, 800]],
            array_combine(array_column($report['rows'], 'situation'), array_map(static fn (array $r): array => [$r['templates'], $r['receita_cents'], $r['despesa_cents']],
                $report['rows'])));
        self::assertSame(['templates' => 10, 'receita_cents' => 12000, 'despesa_cents' => 22900], $report['totals']);
        self::assertSame(['in_force' => 5, 'generated' => 2, 'pending' => 3], $report['generation']);
        self::assertSame(['inactive_link' => 2, 'without_end' => 2], $report['attention']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Portaria', 'Fulano', 'Contrato 123', 'description', 'supplier', '"id"'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem descrição nem vínculo: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], [1, '2026-13'], [1, '11/2026'], [1, []]] as [$condominium, $competence]) {
            try {
                $this->service->build($this->user(), $condominium, $competence);
                self::fail('Entrada inválida passou: ' . json_encode([$condominium, $competence]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(date('Y-m'), $this->service->build($this->user(), 1)['competence'], 'Padrão: o mês atual.');
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/RecurringPortfolio.php');
        foreach (['$this->scope->assertAccess(', 'deleted_at IS NULL', 'RecurringEntries::appliesTo(', 'RecurringEntries::isCompetence('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'description', 'notes', 'NotificationOutbox', 'mail_queue'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem descrição: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/RecurringPortfolio.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.recurring_entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'catch (InvalidArgumentException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/recurring-portfolio", "RecurringPortfolio:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/recurring-entries/close-ended"'), strpos($index, $route), 'Logo depois do encerramento em lote.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
