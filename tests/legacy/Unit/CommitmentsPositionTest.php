<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommitmentsPosition;

/**
 * ADR 0238: posição dos empenhos do condomínio, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para a regra pura (situações do cadastro, tempo de liquidação, liquidação sem lançamento, idade dos em aberto,
 * totais iguais à soma), a montagem sem descrição nem fornecedor, a validação e o escopo; os empenhos do cadastro são
 * conferidos no MariaDB (Integration\CommitmentsPositionTest).
 */
final class CommitmentsPositionTest extends TestCase
{
    private PDO $pdo;
    private CommitmentsPosition $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_commitments (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, description TEXT, amount_cents INTEGER, committed_on TEXT,
                settled_on TEXT, entry_id INTEGER, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new CommitmentsPosition($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{status:string, amount_cents:int, committed_on:string, settled_on:?string, has_entry:bool}> */
    private static function october(): array
    {
        $c = static fn (string $status, int $amount, string $on, ?string $settled, bool $entry): array => ['status' => $status, 'amount_cents' => $amount,
            'committed_on' => $on, 'settled_on' => $settled, 'has_entry' => $entry];
        return [
            $c('active', 10000, '2026-10-02', null, false), $c('active', 20000, '2026-10-20', null, false),
            $c('settled', 5000, '2026-10-03', '2026-10-10', true),     // 7 dias, com lançamento
            $c('settled', 7000, '2026-10-05', '2026-10-25', false),    // 20 dias, sem lançamento
            $c('settled', 3000, '2026-10-06', null, false),            // liquidado sem data: conta, sem tempo
            $c('inactive', 4000, '2026-10-07', null, false),
        ];
    }

    /** @return list<array{committed_on:string, amount_cents:int}> */
    private static function open(): array
    {
        $o = static fn (string $on, int $amount): array => ['committed_on' => $on, 'amount_cents' => $amount];
        return [$o('2026-10-20', 20000), $o('2026-10-02', 10000), $o('2026-09-15', 8000), $o('2026-08-20', 6000), $o('2026-05-01', 1000)];
    }

    public function testSummarizePureRule(): void
    {
        $summary = CommitmentsPosition::summarize(self::october(), self::open(), '2026-10-31');
        self::assertSame([['active', 'Empenhado', 2, 30000], ['settled', 'Liquidado', 3, 15000], ['inactive', 'Cancelado', 1, 4000]],
            array_map(static fn (array $r): array => [$r['status'], $r['status_label'], $r['commitments'], $r['amount_cents']], $summary['rows']));
        self::assertSame(['commitments' => 6, 'amount_cents' => 49000], $summary['totals']);
        foreach (['commitments', 'amount_cents'] as $field) {
            self::assertSame($summary['totals'][$field], array_sum(array_column($summary['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertSame(['count' => 3, 'with_entry' => 1, 'without_entry' => 2, 'dated' => 2, 'days_total' => 27, 'avg_days' => 13.5, 'max_days' => 20],
            $summary['settlement']);
        self::assertSame(['ate_30' => ['commitments' => 2, 'amount_cents' => 30000], 'de_31_a_60' => ['commitments' => 1, 'amount_cents' => 8000],
            'de_61_a_90' => ['commitments' => 1, 'amount_cents' => 6000], 'mais_de_90' => ['commitments' => 1, 'amount_cents' => 1000]], $summary['open']['by_age']);
        self::assertSame(['commitments' => 5, 'amount_cents' => 45000], $summary['open']['total']);
        self::assertSame(['ate_30', 'ate_30', 'de_31_a_60', 'de_31_a_60', 'de_61_a_90', 'de_61_a_90', 'mais_de_90'],
            array_map([CommitmentsPosition::class, 'age'], [0, 30, 31, 60, 61, 90, 91]));
        $empty = CommitmentsPosition::summarize([], [], '2026-10-31');
        self::assertSame([3, 0, null, 0], [count($empty['rows']), $empty['totals']['commitments'], $empty['settlement']['avg_days'], $empty['open']['total']['commitments']]);
    }

    public function testBuildWithoutDescriptionOrSupplier(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_commitments (condominium_id, supplier_id, description, amount_cents, committed_on, settled_on, entry_id, document_id,
            notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::october() as $c) {
            $insert->execute([1, 55, 'Reforma da fachada', $c['amount_cents'], $c['committed_on'], $c['settled_on'], $c['has_entry'] ? 900 : null, 77, 'Contrato 12/2026',
                $c['status'], null]);
        }
        foreach ([['2026-09-15', 8000], ['2026-08-20', 6000], ['2026-05-01', 1000]] as [$on, $amount]) {   // em aberto de antes: só na idade
            $insert->execute([1, null, 'Antigo', $amount, $on, null, null, null, null, 'active', null]);
        }
        $insert->execute([1, null, 'Excluído', 1, '2026-10-05', null, null, null, null, 'active', '2026-10-06 00:00:00']);
        $insert->execute([1, null, 'Depois', 1, '2026-11-02', null, null, null, null, 'active', null]);
        $insert->execute([3, null, 'Outro', 1, '2026-10-05', null, null, null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-10-01', '2026-10-31');
        self::assertSame([6, 49000, 2, 5, 45000], [$report['totals']['commitments'], $report['totals']['amount_cents'], $report['settlement']['without_entry'],
            $report['open']['total']['commitments'], $report['open']['total']['amount_cents']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Reforma', 'Contrato', 'Antigo', 'supplier', 'description', 'document'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem descrição nem fornecedor: ' . $needle);
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/CommitmentsPosition.php');
        foreach (["RegistryService::definition('commitments')", '$this->scope->assertAccess(', 'deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'description', 'supplier_id', 'document_id', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem descrição nem fornecedor: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CommitmentsPosition.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.commitments.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/commitments", "CommitmentsPosition:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
