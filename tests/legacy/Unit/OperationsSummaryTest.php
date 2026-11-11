<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OperationsSummary;

/**
 * ADR 0210: resumo operacional do mês para o síndico. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2), a
 * lógica inteira. Hoje = 2026-11-11; mês = 2026-10; condomínio 2 é outro.
 */
final class OperationsSummaryTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private OperationsSummary $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            "CREATE TABLE erp_occurrences (id INTEGER PRIMARY KEY, condominium_id INTEGER, subject TEXT, priority TEXT, occurred_on TEXT, status TEXT, deleted_at TEXT)",
            "CREATE TABLE erp_maintenances (id INTEGER PRIMARY KEY, condominium_id INTEGER, service TEXT, frequency TEXT DEFAULT 'unica', planned_on TEXT, done_on TEXT, status TEXT, deleted_at TEXT)",
            'CREATE TABLE erp_common_areas (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT)',
            "CREATE TABLE erp_reservations (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, resident_name TEXT, reserved_on TEXT, fee_cents INTEGER,
                status TEXT, deleted_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Brisa'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1), (7, 2)');
        $pdo->exec("INSERT INTO erp_occurrences (condominium_id, subject, priority, occurred_on, status, deleted_at) VALUES
            (1, 'Vazamento no 101 do Fulano', 'alta', '2026-10-05', 'active', NULL), (1, 'Barulho', 'media', '2026-10-20', 'done', NULL),
            (1, 'Lâmpada', 'baixa', '2026-11-10', 'running', NULL), (1, 'Portão', 'media', '2026-09-01', 'active', NULL),
            (1, 'Excluída', 'alta', '2026-10-07', 'active', '2026-10-08'), (2, 'Outro condomínio', 'alta', '2026-10-05', 'active', NULL)");
        $pdo->exec("INSERT INTO erp_maintenances (condominium_id, service, frequency, planned_on, done_on, status, deleted_at) VALUES
            (1, 'Elevador', 'mensal', '2026-10-10', '2026-10-12', 'done', NULL), (1, 'Caixa d''água', 'semestral', '2026-10-25', NULL, 'active', NULL),
            (1, 'Jardim', 'mensal', '2026-11-20', NULL, 'active', NULL), (1, 'Cancelada', 'unica', '2026-10-01', NULL, 'inactive', NULL),
            (1, 'Extintores', 'anual', '2026-09-15', '2026-10-02', 'done', NULL), (1, 'Fachada', 'unica', '2026-12-20', NULL, 'active', NULL),
            (1, 'Bomba', 'trimestral', '2026-11-11', NULL, 'active', NULL), (1, 'Para-raios', 'anual', '2026-08-01', NULL, 'running', NULL),
            (1, 'Excluída', 'unica', '2026-10-05', NULL, 'active', '2026-10-06'), (2, 'Outro', 'unica', '2026-10-05', NULL, 'active', NULL)");
        $pdo->exec("INSERT INTO erp_common_areas (id, condominium_id, name) VALUES (1, 1, 'Salão de festas'), (2, 1, 'Churrasqueira'), (3, 2, 'Piscina')");
        $pdo->exec("INSERT INTO erp_reservations (condominium_id, common_area_id, resident_name, reserved_on, fee_cents, status, deleted_at) VALUES
            (1, 1, 'Fulano de Tal', '2026-10-03', 5000, 'confirmed', NULL), (1, 1, 'Beltrana', '2026-10-17', NULL, 'confirmed', NULL),
            (1, 2, 'Ciclano', '2026-10-10', 3000, 'confirmed', NULL), (1, 1, 'Novembro', '2026-11-07', 5000, 'confirmed', NULL),
            (1, 2, 'Cancelada', '2026-10-11', 3000, 'inactive', NULL), (1, 2, 'Excluída', '2026-10-12', 3000, 'confirmed', '2026-10-01'),
            (1, 1, 'Pendente', '2026-11-20', 5000, 'active', NULL), (1, 1, 'Pendente passada', '2026-11-01', 5000, 'active', NULL),
            (2, 3, 'Outro', '2026-10-03', 9000, 'confirmed', NULL)");
        $this->service = new OperationsSummary($pdo, new CondominiumScope($pdo));
    }

    public function testMonthAndTodayWithoutPersonalData(): void
    {
        $r = $this->service->build($this->user(), 1, '2026-10', self::TODAY);
        self::assertSame(['2026-10-01', '2026-10-31', self::TODAY], [$r['from'], $r['to'], $r['today']]);
        self::assertSame(['total' => 2, 'by_priority' => ['alta' => 1, 'media' => 1, 'baixa' => 0]], $r['occurrences']['opened_in_month'], 'Excluída e outro condomínio fora.');
        self::assertSame(['total' => 3, 'by_priority' => ['alta' => 1, 'media' => 1, 'baixa' => 1], 'by_age' => ['0-7' => 1, '8-30' => 0, '31+' => 2], 'running' => 1],
            $r['occurrences']['open_today']);
        self::assertSame([2, 2, 2, 2, false], [$r['maintenances']['planned_in_month'], $r['maintenances']['done_in_month'], $r['maintenances']['next_30_days'],
            $r['maintenances']['overdue_today']['total'], $r['maintenances']['overdue_today']['truncated']], 'Cancelada fora; a de hoje é dos próximos 30 dias, não atrasada.');
        self::assertSame([['Para-raios', 102], ['Caixa d\'água', 17]], array_map(static fn (array $i): array => [$i['service'], $i['days_late']],
            $r['maintenances']['overdue_today']['items']));
        self::assertSame(['confirmed_in_month' => 3, 'fees_in_month_cents' => 8000,
            'by_area' => [['area' => 'Salão de festas', 'confirmed' => 2, 'fees_cents' => 5000], ['area' => 'Churrasqueira', 'confirmed' => 1, 'fees_cents' => 3000]],
            'pending_approval_today' => 1], $r['reservations']);
        $json = (string)json_encode($r);
        foreach (['Fulano', 'Beltrana', 'Vazamento', 'resident', 'subject', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal nem assunto: ' . $needle);
        }
    }

    public function testPureRulesValidationScopeAndContract(): void
    {
        self::assertSame(['0-7', '0-7', '8-30', '8-30', '31+'], array_map([OperationsSummary::class, 'ageBucket'], [0, 7, 8, 30, 31]));
        self::assertSame([17, -5, 0], [OperationsSummary::days('2026-10-25', '2026-11-11'), OperationsSummary::days('2026-11-11', '2026-11-06'),
            OperationsSummary::days('2026-11-11', '2026-11-11')]);
        $empty = $this->service->build($this->user(), '2', '2026-12', self::TODAY);
        self::assertSame([0, 0, 1], [$empty['occurrences']['opened_in_month']['total'], $empty['reservations']['confirmed_in_month'], $empty['occurrences']['open_today']['total']]);
        foreach ([[null, '2026-10'], ['x', '2026-10'], [0, '2026-10'], [[1], '2026-10'], [1, '2026-13'], [1, null], [1, '10/2026']] as [$c, $m]) {
            try {
                $this->service->build($this->user(), $c, $m, self::TODAY);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3, '2026-10', self::TODAY);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/OperationsSummary.php');
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::record', 'NotificationOutbox', 'resident_name', 'description'] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/OperationsSummary.php');
        foreach (["'erp.occurrences.view'", "'erp.maintenances.view'", "'erp.reservations.view'", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/operations-summary", "OperationsSummary:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
