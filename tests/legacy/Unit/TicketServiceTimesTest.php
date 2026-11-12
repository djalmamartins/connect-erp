<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\TicketServiceTimes;

/**
 * ADR 0226: tempo de atendimento dos chamados do condomínio, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2) para a regra pura (no prazo, atrasado, concluído sem data, vencido em aberto, totais iguais à soma das
 * linhas), a montagem, a validação e o escopo; os chamados do TicketService são conferidos no MariaDB
 * (Integration\TicketServiceTimesTest).
 */
final class TicketServiceTimesTest extends TestCase
{
    private PDO $pdo;
    private TicketServiceTimes $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE studio_support_tickets (id INTEGER PRIMARY KEY, condominium_id INTEGER, protocol TEXT, subject TEXT, requester_name TEXT, area TEXT,
                priority TEXT, status TEXT, created_at TEXT, due_at TEXT, resolved_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new TicketServiceTimes($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{area:string, priority:string, status:string, created_at:string, due_at:?string, resolved_at:?string}> */
    private static function october(): array
    {
        $t = static fn (string $area, string $priority, string $status, string $created, ?string $due, ?string $resolved): array => ['area' => $area,
            'priority' => $priority, 'status' => $status, 'created_at' => $created, 'due_at' => $due, 'resolved_at' => $resolved];
        return [
            $t('technical', 'high', 'resolved', '2026-10-01 08:00:00', '2026-10-03 08:00:00', '2026-10-02 08:00:00'),   // 24 h, no prazo
            $t('technical', 'high', 'closed', '2026-10-05 08:00:00', '2026-10-07 08:00:00', '2026-10-08 08:00:00'),     // 72 h, atrasado
            $t('technical', 'high', 'closed', '2026-10-06 08:00:00', '2026-10-08 08:00:00', null),                      // concluído sem data
            $t('technical', 'urgent', 'open', '2026-10-30 10:00:00', '2026-10-31 10:00:00', null),                      // vencido em aberto
            $t('general', 'medium', 'waiting_customer', '2026-10-31 09:00:00', '2026-11-03 09:00:00', null),            // em aberto no prazo
            $t('financial', 'low', 'resolved', '2026-10-10 00:00:00', '2026-10-15 00:00:00', '2026-10-10 06:00:00'),     // 6 h, no prazo
        ];
    }

    public function testSummarizePureRule(): void
    {
        $summary = TicketServiceTimes::summarize(self::october(), '2026-11-01 00:00:00');
        self::assertSame([
            ['general', 'medium', 72, 1, 0, 1, 0, 0, 0, 0, 0, null, null],
            ['technical', 'urgent', 24, 1, 0, 1, 0, 0, 0, 1, 0, null, null],
            ['technical', 'high', 48, 3, 3, 0, 2, 1, 1, 0, 5760, 48.0, 50.0],
            ['financial', 'low', 120, 1, 1, 0, 1, 1, 0, 0, 360, 6.0, 100.0],
        ], array_map(static fn (array $r): array => [$r['area'], $r['priority'], $r['sla_hours'], $r['created'], $r['done'], $r['open'], $r['timed'],
            $r['on_time'], $r['late'], $r['overdue_open'], $r['resolution_minutes_total'], $r['avg_resolution_hours'], $r['on_time_pct']], $summary['rows']));
        self::assertSame(['created' => 6, 'done' => 4, 'open' => 2, 'timed' => 3, 'on_time' => 2, 'late' => 1, 'overdue_open' => 1,
            'resolution_minutes_total' => 6120, 'avg_resolution_hours' => 34.0, 'on_time_pct' => 66.7], $summary['totals']);
        foreach (['created', 'done', 'open', 'timed', 'on_time', 'late', 'overdue_open', 'resolution_minutes_total'] as $field) {
            self::assertSame($summary['totals'][$field], array_sum(array_column($summary['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertSame(['Geral', 'Média'], [$summary['rows'][0]['area_label'], $summary['rows'][0]['priority_label']]);
        self::assertSame(['created' => 0, 'done' => 0, 'open' => 0, 'timed' => 0, 'on_time' => 0, 'late' => 0, 'overdue_open' => 0,
            'resolution_minutes_total' => 0, 'avg_resolution_hours' => null, 'on_time_pct' => null], TicketServiceTimes::summarize([], '2026-11-01 00:00:00')['totals']);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO studio_support_tickets (condominium_id, protocol, subject, requester_name, area, priority, status, created_at, due_at,
            resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::october() as $i => $t) {
            $insert->execute([1, 'CH' . $i, 'Vazamento do Fulano', 'Fulano de Tal', $t['area'], $t['priority'], $t['status'], $t['created_at'], $t['due_at'], $t['resolved_at']]);
        }
        $insert->execute([3, 'CHX', 'Outro', 'Outro', 'general', 'low', 'open', '2026-10-10 00:00:00', '2026-10-15 00:00:00', null]);       // outro condomínio
        $insert->execute([1, 'CHY', 'Antigo', 'Antigo', 'general', 'low', 'open', '2026-09-30 23:59:59', '2026-10-05 00:00:00', null]);    // antes do período
        $report = $this->service->build($this->user(), '1', '2026-10-01', '2026-10-31');
        self::assertSame([6, 1, 34.0], [$report['totals']['created'], $report['totals']['overdue_open'], $report['totals']['avg_resolution_hours']]);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Vazamento', 'CH0', 'protocol', 'requester'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do chamado: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, '01/10/2026', '2026-10-31'], [1, '2026-11-01', '2026-10-31'], [1, '2025-01-01', '2026-10-31'],
            [1, '2026-10-01', '2026-02-30']] as [$c, $from, $to]) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/TicketServiceTimes.php');
        foreach (['TicketService::slaHours(', 'TicketService::AREA_LABELS', 'TicketService::PRIORITY_LABELS', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'subject', 'message', 'requester', 'protocol', 'category', 'unit_id', 'assigned'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do chamado: ' . $needle);
        }
        self::assertStringContainsString("Access::can('erp.access', \$user)", (string)file_get_contents($root . '/source/Controllers/Api/V1/TicketServiceTimes.php'));
        self::assertStringContainsString('$route->get("/reports/ticket-service-times", "TicketServiceTimes:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
