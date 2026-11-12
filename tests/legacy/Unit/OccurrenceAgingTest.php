<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OccurrenceAging;

/**
 * ADR 0256: idade das ocorrências em aberto por prioridade. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para
 * a regra pura (abertas, em andamento, faixas de idade, com chamado, antigas sem chamado, resolvidas; total = soma das
 * linhas), a consulta sem dado da ocorrência, a validação, o escopo e o contrato; as ocorrências gravadas pelo cadastro
 * são conferidas no MariaDB (Integration\OccurrenceAgingTest).
 */
final class OccurrenceAgingTest extends TestCase
{
    private PDO $pdo;
    private OccurrenceAging $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_occurrences (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, subject TEXT, priority TEXT, occurred_on TEXT, description TEXT,
                document_id INTEGER, ticket_id INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new OccurrenceAging($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $o = static fn (string $priority, string $status, string $on, bool $ticket = false): array => ['priority' => $priority, 'status' => $status,
            'occurred_on' => $on, 'has_ticket' => $ticket];
        $summary = OccurrenceAging::summarize([$o('alta', 'active', '2026-11-01'), $o('alta', 'running', '2026-08-01', true), $o('alta', 'active', '2026-07-01'),
            $o('media', 'active', '2026-09-13'), $o('media', 'done', '2025-01-01'), $o('baixa', 'active', '2026-08-14'), $o('xyz', 'running', '2026-11-12')], '2026-11-12');
        self::assertSame(['baixa', 'media', 'alta'], array_column($summary['rows'], 'priority'), 'As prioridades vêm do cadastro.');
        self::assertSame([1, 1, 2], array_column($summary['rows'], 'open'));
        self::assertSame([0, 1, 1], array_column($summary['rows'], 'running'), 'Desconhecida vira Média.');
        self::assertSame([0, 1, 1], array_column($summary['rows'], 'age_0_30'));
        self::assertSame([1, 1, 0], array_column($summary['rows'], 'age_31_90'), '90 dias ainda é 31 a 90.');
        self::assertSame([0, 0, 2], array_column($summary['rows'], 'age_over_90'));
        self::assertSame([0, 0, 1], array_column($summary['rows'], 'with_ticket'));
        self::assertSame([0, 0, 1], array_column($summary['rows'], 'stale_without_ticket'), 'A antiga com chamado não conta como sem chamado.');
        self::assertSame([0, 1, 0], array_column($summary['rows'], 'done'));
        foreach (array_keys($summary['totals']) as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame($summary['totals']['open'] + $summary['totals']['running'],
            $summary['totals']['age_0_30'] + $summary['totals']['age_31_90'] + $summary['totals']['age_over_90'], 'As faixas somam as em aberto.');
        self::assertSame('2026-07-01', $summary['oldest_open_on']);
        self::assertNull(OccurrenceAging::summarize([$o('alta', 'done', '2026-01-01')], '2026-11-12')['oldest_open_on'], 'Resolvida não conta como aberta.');
    }

    public function testBuildWithoutOccurrenceData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_occurrences (condominium_id, unit_id, subject, priority, occurred_on, description, document_id, ticket_id, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 40, 'Vazamento no 101', 'alta', '2026-06-01', 'Relato do Fulano', 70, 900, 'running', null]);
        $insert->execute([1, null, 'Barulho', 'baixa', '2026-11-01', 'Relato', null, null, 'active', null]);
        $insert->execute([1, null, 'Depois da data', 'baixa', '2026-11-20', 'Relato', null, null, 'active', null]);
        $insert->execute([3, null, 'De fora', 'alta', '2026-01-01', 'Relato', null, null, 'active', null]);
        $insert->execute([1, null, 'Excluída', 'alta', '2026-01-01', 'Relato', null, null, 'active', '2026-01-02 00:00:00']);
        $report = $this->service->build($this->user(), '1', '2026-11-12');
        self::assertSame([1, '2026-11-12', 1, 1, 1, 1, '2026-06-01'], [$report['condominium_id'], $report['on'], $report['totals']['open'], $report['totals']['running'],
            $report['totals']['age_over_90'], $report['totals']['with_ticket'], $report['oldest_open_on']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Vazamento', 'Fulano', 'Barulho', 'subject', 'description', 'ticket_id', 'unit'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado da ocorrência: ' . $needle);
        }
        self::assertSame(date('Y-m-d'), $this->service->build($this->user(), 1)['on'], 'Data padrão: hoje.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], ['1.5', null], [1, '2026-02-30'], [1, '12/11/2026'], [1, ['x']]] as [$c, $on]) {
            try {
                $this->service->build($this->user(), $c, $on);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $on]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/OccurrenceAging.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('occurrences')", 'WHERE condominium_id = ? AND deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'subject', 'description', 'unit_id', 'document_id', 'SELECT ticket_id'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado da ocorrência: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/OccurrenceAging.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.occurrences.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/occurrence-aging", "OccurrenceAging:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
