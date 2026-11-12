<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MaintenanceCompliance;

/**
 * ADR 0248: cumprimento do plano de manutenções. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra
 * pura da situação e do atraso, o resumo por periodicidade (total = soma das linhas, médias e taxa), a consulta no período
 * com o laudo do mesmo condomínio e sem dado da manutenção, a validação, o escopo e o contrato; as manutenções gravadas
 * pelo cadastro são conferidas no MariaDB (Integration\MaintenanceComplianceTest).
 */
final class MaintenanceComplianceTest extends TestCase
{
    private PDO $pdo;
    private MaintenanceCompliance $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE erp_maintenances (id INTEGER PRIMARY KEY, condominium_id INTEGER, service TEXT, supplier_id INTEGER, planned_on TEXT, frequency TEXT,
                done_on TEXT, document_id INTEGER, description TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new MaintenanceCompliance($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testClassifyAndSummarizePureRule(): void
    {
        $ref = '2026-11-11';
        self::assertSame([['done_on_time', 0], ['done_on_time', 0], ['done_late', 10], ['overdue', 0], ['upcoming', 0], ['overdue', 0], ['cancelled', 0]], [
            MaintenanceCompliance::classify('done', '2026-10-01', '2026-10-01', $ref), MaintenanceCompliance::classify('done', '2026-10-01', null, $ref),
            MaintenanceCompliance::classify('done', '2026-09-01', '2026-09-11', $ref), MaintenanceCompliance::classify('active', '2026-11-10', null, $ref),
            MaintenanceCompliance::classify('active', '2026-11-11', null, $ref), MaintenanceCompliance::classify('running', '2026-10-20', null, $ref),
            MaintenanceCompliance::classify('inactive', '2026-10-01', null, $ref)]);

        $m = static fn (string $f, string $s, string $p, ?string $d, bool $doc = true): array => ['frequency' => $f, 'status' => $s, 'planned_on' => $p,
            'done_on' => $d, 'has_document' => $doc];
        $summary = MaintenanceCompliance::summarize([
            $m('mensal', 'done', '2026-10-01', '2026-10-01'), $m('mensal', 'done', '2026-09-01', '2026-09-11', false), $m('mensal', 'active', '2026-11-01', null),
            $m('mensal', 'active', '2026-11-11', null), $m('anual', 'done', '2026-03-01', '2026-03-31'), $m('anual', 'done', '2026-02-01', null),
            $m('anual', 'inactive', '2026-04-01', null), $m('trimestral', 'running', '2026-10-20', null), $m('quinzenal', 'done', '2026-05-01', '2026-04-30'),
        ], $ref);
        self::assertSame(['unica', 'mensal', 'trimestral', 'semestral', 'anual'], array_column($summary['rows'], 'frequency'), 'As periodicidades vêm do cadastro.');
        self::assertSame([1, 4, 1, 0, 2], array_column($summary['rows'], 'planned'));
        self::assertSame(['frequency' => 'mensal', 'frequency_label' => 'Mensal', 'planned' => 4, 'done_on_time' => 1, 'done_late' => 1, 'overdue' => 1, 'upcoming' => 1,
            'cancelled' => 0, 'done_without_document' => 1, 'avg_delay_days' => 10, 'on_time_rate' => 33], $summary['rows'][1]);
        self::assertSame([100, 33, 0, null, 50], array_column($summary['rows'], 'on_time_rate'));
        self::assertSame([null, 10, null, null, 30], array_column($summary['rows'], 'avg_delay_days'));
        self::assertSame(['planned' => 8, 'done_on_time' => 3, 'done_late' => 2, 'overdue' => 2, 'upcoming' => 1, 'cancelled' => 1, 'done_without_document' => 1,
            'avg_delay_days' => 20, 'on_time_rate' => 43], $summary['totals']);
        foreach (['planned', 'done_on_time', 'done_late', 'overdue', 'upcoming', 'cancelled', 'done_without_document'] as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        $empty = MaintenanceCompliance::summarize([], $ref);
        self::assertSame([5, 0, null, null], [count($empty['rows']), $empty['totals']['planned'], $empty['totals']['on_time_rate'], $empty['totals']['avg_delay_days']]);
    }

    public function testBuildInThePeriodWithoutMaintenanceData(): void
    {
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (51, 1, NULL), (52, 1, '2026-01-01 00:00:00')");
        $insert = $this->pdo->prepare('INSERT INTO erp_maintenances (condominium_id, service, planned_on, frequency, done_on, document_id, description, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'Revisão do elevador', '2026-02-01', 'mensal', '2026-02-01', 51, 'Laudo interno', 'done', null]);
        $insert->execute([1, 'Bombas', '2026-03-01', 'mensal', '2026-03-05', 52, null, 'done', null]);           // laudo removido
        $insert->execute([1, 'Extintores', '2026-06-01', 'mensal', null, null, null, 'active', null]);
        $insert->execute([1, 'Para-raios', '2026-06-30', 'anual', null, null, null, 'active', null]);            // no dia da referência
        $insert->execute([1, 'Fora do período', '2025-12-31', 'mensal', null, null, null, 'active', null]);
        $insert->execute([1, 'Excluída', '2026-03-01', 'mensal', null, null, null, 'active', '2026-03-02 00:00:00']);
        $insert->execute([3, 'Outro condomínio', '2026-03-01', 'mensal', null, null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-01-01', '2026-06-30');
        self::assertSame([1, '2026-01-01', '2026-06-30', '2026-06-30'], [$report['condominium_id'], $report['from'], $report['to'], $report['reference_on']]);
        self::assertSame([0, 3, 0, 0, 1], array_column($report['rows'], 'planned'));
        self::assertSame(['planned' => 4, 'done_on_time' => 1, 'done_late' => 1, 'overdue' => 1, 'upcoming' => 1, 'cancelled' => 0, 'done_without_document' => 1,
            'avg_delay_days' => 4, 'on_time_rate' => 33], $report['totals']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['elevador', 'Bombas', 'Laudo', 'service', 'document_id', 'supplier'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado da manutenção: ' . $needle);
        }
        $default = $this->service->build($this->user(), 1);
        self::assertSame([date('Y-m-d'), (new \DateTimeImmutable('-1 year +1 day'))->format('Y-m-d')], [$default['to'], $default['from']], 'Padrão: 12 meses até hoje.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, '2026-02-30', null], [1, null, '31/12/2026'], [1, '2026-06-01', '2026-05-01'],
            [1, '2020-01-01', '2026-01-01']] as [$c, $from, $to]) {
            try {
                $this->service->build($this->user(), $c, $from, $to);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $from, $to]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/MaintenanceCompliance.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('maintenances')", 'm.deleted_at IS NULL AND m.planned_on BETWEEN ? AND ?',
            'd.condominium_id = m.condominium_id AND d.deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'm.service', 'm.description', 'supplier_id'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado da manutenção: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MaintenanceCompliance.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.maintenances.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/maintenance-compliance", "MaintenanceCompliance:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
