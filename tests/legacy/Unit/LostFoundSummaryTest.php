<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LostFoundSummary;

/**
 * ADR 0232: resumo dos achados e perdidos do condomínio, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2) para a regra pura (situações do cadastro, tempo de devolução, idade dos guardados, totais iguais à soma), a
 * montagem sem dado do item, a validação e o escopo; os itens do cadastro são conferidos no MariaDB
 * (Integration\LostFoundSummaryTest).
 */
final class LostFoundSummaryTest extends TestCase
{
    private PDO $pdo;
    private LostFoundSummary $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_lost_found (id INTEGER PRIMARY KEY, condominium_id INTEGER, item TEXT, location TEXT, found_on TEXT, found_by TEXT, returned_on TEXT,
                returned_to TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new LostFoundSummary($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{status:string, found_on:string, returned_on:?string}> */
    private static function october(): array
    {
        $i = static fn (string $status, string $found, ?string $back): array => ['status' => $status, 'found_on' => $found, 'returned_on' => $back];
        return [
            $i('active', '2026-10-02', null), $i('active', '2026-10-25', null),
            $i('returned', '2026-10-03', '2026-10-05'),    // 2 dias
            $i('returned', '2026-10-10', '2026-10-20'),    // 10 dias
            $i('returned', '2026-10-12', null),            // devolvido sem data: conta, sem tempo
            $i('returned', '2026-10-15', '2026-10-14'),    // devolução antes do achado: conta, sem tempo
            $i('discarded', '2026-10-04', null),
        ];
    }

    /** @return list<array{found_on:string}> */
    private static function kept(): array
    {
        return [['found_on' => '2026-10-25'], ['found_on' => '2026-10-02'], ['found_on' => '2026-09-01'], ['found_on' => '2026-07-01'], ['found_on' => '2026-01-10']];
    }

    public function testSummarizePureRule(): void
    {
        $summary = LostFoundSummary::summarize(self::october(), self::kept(), '2026-10-31');
        self::assertSame([['active', 'Guardado', 2], ['returned', 'Devolvido', 4], ['discarded', 'Descartado', 1]],
            array_map(static fn (array $r): array => [$r['status'], $r['status_label'], $r['items']], $summary['rows']));
        self::assertSame(['items' => 7], $summary['totals']);
        self::assertSame($summary['totals']['items'], array_sum(array_column($summary['rows'], 'items')), 'Total = soma das linhas.');
        self::assertSame(['count' => 4, 'dated' => 2, 'days_total' => 12, 'avg_days' => 6.0, 'max_days' => 10], $summary['returns']);
        self::assertSame(['ate_30' => 2, 'de_31_a_90' => 1, 'de_91_a_180' => 1, 'mais_de_180' => 1, 'total' => 5], $summary['custody']);
        self::assertSame(['ate_30', 'ate_30', 'de_31_a_90', 'de_31_a_90', 'de_91_a_180', 'de_91_a_180', 'mais_de_180'],
            array_map([LostFoundSummary::class, 'age'], [0, 30, 31, 90, 91, 180, 181]));
        $empty = LostFoundSummary::summarize([], [], '2026-10-31');
        self::assertSame([0, null, null, 0], [$empty['totals']['items'], $empty['returns']['avg_days'], $empty['returns']['max_days'], $empty['custody']['total']]);
    }

    public function testBuildWithoutItemData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_lost_found (condominium_id, item, location, found_on, found_by, returned_on, returned_to, notes, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::october() as $i) {
            $insert->execute([1, 'Carteira marrom', 'Salão de festas', $i['found_on'], 'Porteiro Zé', $i['returned_on'], $i['returned_on'] !== null ? 'Fulano de Tal' : null,
                'Com documentos', $i['status'], null]);
        }
        foreach (['2026-09-01', '2026-07-01', '2026-01-10'] as $on) {    // guardados de antes do período: só na idade
            $insert->execute([1, 'Guarda-chuva', 'Garagem', $on, null, null, null, null, 'active', null]);
        }
        $insert->execute([1, 'Excluído', null, '2026-10-05', null, null, null, null, 'active', '2026-10-06 00:00:00']);
        $insert->execute([1, 'Depois', null, '2026-11-02', null, null, null, null, 'active', null]);
        $insert->execute([3, 'Outro', null, '2026-10-05', null, null, null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-10-01', '2026-10-31');
        self::assertSame([7, 4, 2, 5], [$report['totals']['items'], $report['returns']['count'], $report['returns']['dated'], $report['custody']['total']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Carteira', 'Salão', 'Zé', 'Fulano', 'documentos', 'Guarda-chuva', 'returned_to', 'found_by'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do item: ' . $needle);
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/LostFoundSummary.php');
        foreach (["RegistryService::definition('lost_found')", '$this->scope->assertAccess(', 'deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT item', 'location', 'found_by', 'returned_to', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do item: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/LostFoundSummary.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.lost_found.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/lost-found", "LostFoundSummary:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
