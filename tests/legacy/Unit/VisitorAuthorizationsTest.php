<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\VisitorAuthorizations;

/**
 * ADR 0234: situação das autorizações de visitantes numa data, somente leitura. Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2) para a regra pura (as seis situações com as bordas da janela, os vencidos por idade, totais
 * iguais à soma), a montagem sem dado pessoal, a validação e o escopo; os visitantes do cadastro são conferidos no
 * MariaDB (Integration\VisitorAuthorizationsTest).
 */
final class VisitorAuthorizationsTest extends TestCase
{
    private PDO $pdo;
    private VisitorAuthorizations $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_visitors (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, document TEXT, phone TEXT,
                permanent_access INTEGER, valid_until TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new VisitorAuthorizations($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{status:string, permanent_access:bool, valid_until:?string}> */
    private static function visitors(): array
    {
        $v = static fn (string $status, bool $permanent, ?string $until): array => ['status' => $status, 'permanent_access' => $permanent, 'valid_until' => $until];
        return [
            $v('active', true, null), $v('active', true, '2026-01-01'),            // permanente (a data não conta)
            $v('active', false, '2027-01-10'),                                     // autorizado, fora da janela
            $v('active', false, '2026-11-11'), $v('active', false, '2026-12-11'),  // vence na janela (as duas bordas)
            $v('active', false, '2026-11-10'),                                     // vencido há 1 dia
            $v('active', false, '2026-09-01'),                                     // vencido há 71 dias
            $v('active', false, '2026-01-01'),                                     // vencido há 314 dias
            $v('active', false, null),                                             // sem data nem permanente
            $v('inactive', true, null), $v('inactive', false, '2026-01-01'),       // bloqueados
        ];
    }

    public function testSummarizePureRule(): void
    {
        $summary = VisitorAuthorizations::summarize(self::visitors(), '2026-11-11', 30);
        self::assertSame('2026-12-11', $summary['window_until']);
        self::assertSame([['permanent', 2, 'Liberado'], ['valid', 1, 'Liberado'], ['expiring', 2, 'Liberado'], ['expired_active', 3, 'Liberado'],
            ['no_date', 1, 'Liberado'], ['blocked', 2, 'Bloqueado']],
            array_map(static fn (array $r): array => [$r['situation'], $r['visitors'], $r['status_label']], $summary['rows']));
        self::assertSame(['visitors' => 11, 'active' => 9, 'attention' => 4], $summary['totals']);
        self::assertSame($summary['totals']['visitors'], array_sum(array_column($summary['rows'], 'visitors')), 'Total = soma das linhas.');
        self::assertSame(['ate_30' => 1, 'de_31_a_90' => 1, 'mais_de_90' => 1], $summary['expired_by_age']);
        self::assertSame($summary['rows'][3]['visitors'], array_sum($summary['expired_by_age']), 'Idades somam os vencidos liberados.');
        self::assertSame('expiring', VisitorAuthorizations::classify(['status' => 'active', 'valid_until' => '2026-11-12'], '2026-11-11', '2026-11-12'));
        self::assertSame('valid', VisitorAuthorizations::classify(['status' => 'active', 'valid_until' => '2026-11-13'], '2026-11-11', '2026-11-12'));
        $empty = VisitorAuthorizations::summarize([], '2026-11-11', 30);
        self::assertSame([6, 0, 0], [count($empty['rows']), $empty['totals']['visitors'], $empty['totals']['attention']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_visitors (condominium_id, unit_id, unit_label, name, document, phone, permanent_access, valid_until, notes, status,
            deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::visitors() as $v) {
            $insert->execute([1, 11, 'Apto 101', 'Fulano Diarista', '12345678909', '11988887777', $v['permanent_access'] ? 1 : 0, $v['valid_until'], 'Às terças',
                $v['status'], null]);
        }
        $insert->execute([1, 11, 'Apto 101', 'Excluído', null, null, 0, '2026-01-01', null, 'active', '2026-02-01 00:00:00']);
        $insert->execute([3, 31, 'Apto 9', 'Outro', null, null, 0, '2026-01-01', null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-11-11', '30');
        self::assertSame([11, 9, 4, 30], [$report['totals']['visitors'], $report['totals']['active'], $report['totals']['attention'], $report['within_days']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', '12345678909', '11988887777', 'Apto', 'terças', 'unit', 'document', 'phone'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, '11/11/2026', null], [1, '2026-02-30', null], [1, null, '0'], [1, null, '366'], [1, null, 'x']]
            as [$c, $on, $within]) {
            try {
                $this->service->build($this->user(), $c, $on, $within);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $on, $within]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/VisitorAuthorizations.php');
        foreach (["RegistryService::definition('visitors')", '$this->scope->assertAccess(', 'deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', 'document', 'phone', 'unit_id', 'unit_label', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VisitorAuthorizations.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.visitors.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/visitor-authorizations", "VisitorAuthorizations:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
