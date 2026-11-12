<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LegalCasePortfolio;

/**
 * ADR 0250: carteira da cobrança judicial. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura do
 * resumo por fase (somas, parados, sem advogado, idade média, abertos e encerrados em 12 meses; total = soma das linhas), a
 * consulta com as contas ligadas e sem dado do processo, a validação, o escopo e o contrato; os processos com as contas do
 * cadastro são conferidos no MariaDB (Integration\LegalCasePortfolioTest).
 */
final class LegalCasePortfolioTest extends TestCase
{
    private PDO $pdo;
    private LegalCasePortfolio $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, amount_cents INTEGER, paid_on TEXT, description TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_legal_cases (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, lawyer_id INTEGER, status TEXT, case_number TEXT, court TEXT,
                opened_on TEXT, claimed_cents INTEGER, closed_on TEXT, close_reason TEXT)',
            'CREATE TABLE erp_legal_case_entries (case_id INTEGER, entry_id INTEGER, amount_cents INTEGER)',
            'CREATE TABLE erp_legal_case_events (id INTEGER PRIMARY KEY, case_id INTEGER, kind TEXT, note TEXT, happened_on TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new LegalCasePortfolio($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $c = static fn (string $status, string $opened, ?string $closed, int $claimed, int $recovered, int $open, bool $lawyer, ?string $last): array => [
            'status' => $status, 'opened_on' => $opened, 'closed_on' => $closed, 'claimed_cents' => $claimed, 'recovered_cents' => $recovered,
            'still_open_cents' => $open, 'has_lawyer' => $lawyer, 'last_event_on' => $last];
        $summary = LegalCasePortfolio::summarize([
            $c('preparing', '2026-10-01', null, 1000, 0, 1000, false, '2026-10-01'),
            $c('filed', '2025-11-11', null, 5000, 2000, 3000, true, '2026-08-12'),    // parado: 91 dias sem andamento
            $c('filed', '2026-01-01', null, 2000, 0, 2000, true, '2026-08-13'),       // 90 dias: ainda não
            $c('suspended', '2026-05-15', null, 3000, 0, 3000, true, null),           // sem andamento: conta a abertura
            $c('closed', '2025-01-01', '2026-03-01', 4000, 4000, 0, true, '2026-03-01'),
            $c('closed', '2023-01-01', '2024-01-01', 2500, 0, 0, false, '2024-01-01'),
            $c('agreement', '2026-06-01', null, 1500, 500, 1000, true, '2026-11-01'),
            $c('desconhecida', '2026-11-11', null, 0, 0, 0, false, '2026-11-11'),
        ], '2026-11-11', 90);
        self::assertSame(['preparing', 'filed', 'suspended', 'agreement', 'closed'], array_column($summary['rows'], 'status'), 'As fases vêm da cobrança.');
        self::assertSame([2, 2, 1, 1, 2], array_column($summary['rows'], 'cases'));
        self::assertSame([0, 1, 1, 0, 0], array_column($summary['rows'], 'stale'), 'Encerrado nunca fica parado.');
        self::assertSame([21, 340, 180, 163, 395], array_column($summary['rows'], 'avg_age_days'));
        self::assertSame(['status' => 'filed', 'status_label' => 'Ajuizado', 'cases' => 2, 'claimed_cents' => 7000, 'recovered_cents' => 2000, 'still_open_cents' => 5000,
            'stale' => 1, 'without_lawyer' => 0, 'avg_age_days' => 340], $summary['rows'][1]);
        self::assertSame(['cases' => 8, 'claimed_cents' => 19000, 'recovered_cents' => 6500, 'still_open_cents' => 10000, 'stale' => 2, 'without_lawyer' => 3,
            'avg_age_days' => 232], $summary['totals']);
        foreach (['cases', 'claimed_cents', 'recovered_cents', 'still_open_cents', 'stale', 'without_lawyer'] as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame([5, 1], [$summary['opened_12m'], $summary['closed_12m']]);
        $empty = LegalCasePortfolio::summarize([], '2026-11-11');
        self::assertSame([5, 0, null], [count($empty['rows']), $empty['totals']['cases'], $empty['totals']['avg_age_days']]);
    }

    public function testBuildWithoutCaseData(): void
    {
        $this->pdo->exec("INSERT INTO erp_entries (id, condominium_id, amount_cents, paid_on, description, status, deleted_at) VALUES
            (1, 1, 100000, '2026-10-01', 'Cota', 'active', NULL), (2, 1, 160000, NULL, 'Cota', 'active', NULL), (3, 1, 50000, NULL, 'Cota', 'inactive', NULL),
            (4, 1, 70000, NULL, 'Cota', 'active', '2026-10-01 00:00:00'), (5, 3, 90000, NULL, 'Cota', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_legal_cases (id, condominium_id, unit_id, lawyer_id, status, case_number, court, opened_on, claimed_cents, closed_on) VALUES
            (1, 1, 11, 5, 'filed', 'Proc 0001234-55', 'Vara Cível', '2026-01-10', 370000, NULL),
            (2, 1, 12, NULL, 'closed', NULL, NULL, '2025-01-10', 80000, '2025-06-10'),
            (3, 3, 31, NULL, 'filed', 'Proc fora', NULL, '2026-01-10', 90000, NULL)");
        $this->pdo->exec('INSERT INTO erp_legal_case_entries (case_id, entry_id, amount_cents) VALUES (1, 1, 100000), (1, 2, 150000), (1, 3, 50000), (1, 4, 70000), (3, 5, 90000)');
        $this->pdo->exec("INSERT INTO erp_legal_case_events (id, case_id, kind, note, happened_on) VALUES (1, 1, 'opened', 'Andamento sigiloso', '2026-01-10'),
            (2, 1, 'note', 'Citação', '2026-10-20')");
        $report = $this->service->build($this->user(), '1', '30');
        self::assertSame([1, 30, date('Y-m-d')], [$report['condominium_id'], $report['stale_days'], $report['on']]);
        self::assertSame(['cases' => 1, 'claimed_cents' => 370000, 'recovered_cents' => 100000, 'still_open_cents' => 160000], array_intersect_key($report['rows'][1],
            array_flip(['cases', 'claimed_cents', 'recovered_cents', 'still_open_cents'])), 'Paga pelo valor cobrado; em aberto pelo valor atual; cancelada e excluída fora.');
        self::assertSame([2, 450000, 1], [$report['totals']['cases'], $report['totals']['claimed_cents'], $report['totals']['without_lawyer']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Proc', 'Vara', 'sigiloso', 'Citação', 'unit_id', 'lawyer_id', 'case_number'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do processo: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], [1, '29'], [1, '366'], [1, 'x']] as [$c, $stale]) {
            try {
                $this->service->build($this->user(), $c, $stale);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $stale]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/LegalCasePortfolio.php');
        foreach (['$this->scope->assertAccess(', 'Collections::CASE_STATUSES', "e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NULL THEN e.amount_cents"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'case_number', 'court', 'ev.note', 'e.description', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do processo: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/LegalCasePortfolio.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.collections.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/legal-cases", "LegalCasePortfolio:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
