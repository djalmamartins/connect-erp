<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InsuranceCoverage;

/**
 * ADR 0244: cobertura de seguros numa data. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura das
 * faixas e dos limites, a cobertura emendada, o resumo (total = soma das linhas), a consulta com o PDF do mesmo condomínio
 * e sem dado da apólice, a validação, o escopo e o contrato; as apólices gravadas pelo cadastro são conferidas no MariaDB
 * (Integration\InsuranceCoverageTest).
 */
final class InsuranceCoverageTest extends TestCase
{
    private PDO $pdo;
    private InsuranceCoverage $service;

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
            'CREATE TABLE erp_insurances (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, insurer TEXT, policy_number TEXT, starts_on TEXT,
                ends_on TEXT, premium_cents INTEGER, coverage_cents INTEGER, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new InsuranceCoverage($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{0:string, 1:string, 2:bool}> início, fim e PDF, com a data de referência 2026-11-11 */
    private static function policies(): array
    {
        return [
            ['2025-11-01', '2026-11-10', true],    // vencida ontem
            ['2025-11-11', '2026-11-11', false],   // termina hoje: ainda em vigor
            ['2025-12-01', '2026-12-11', true],    // 30 dias
            ['2026-01-01', '2026-12-12', true],    // 31 dias
            ['2026-01-01', '2027-02-09', true],    // 90 dias
            ['2026-01-01', '2027-02-10', true],    // 91 dias
            ['2027-02-11', '2028-02-10', false],   // renovação emendada no dia seguinte
        ];
    }

    public function testBucketsCoverageAndSummarizePureRule(): void
    {
        $on = '2026-11-11';
        self::assertSame(['expired', 'renew_0_30', 'renew_0_30', 'renew_31_90', 'renew_31_90', 'in_force_over_90', 'not_started'],
            array_map(static fn (array $p): string => InsuranceCoverage::bucket($p[0], $p[1], $on), self::policies()));
        self::assertSame('renew_0_30', InsuranceCoverage::bucket('2026-01-01 00:00:00', '2026-11-20 00:00:00', $on));

        $summary = InsuranceCoverage::summarize(array_map(static fn (array $p): array => ['starts_on' => $p[0], 'ends_on' => $p[1], 'premium_cents' => 100,
            'coverage_cents' => 1000, 'has_pdf' => $p[2]], self::policies()), $on, 2);
        self::assertSame(array_keys(InsuranceCoverage::BUCKETS), array_column($summary['rows'], 'bucket'));
        self::assertSame([1, 2, 2, 1, 1], array_column($summary['rows'], 'policies'));
        self::assertSame([100, 200, 200, 100, 100], array_column($summary['rows'], 'premium_cents'));
        self::assertSame([0, 1, 0, 0, 1], array_column($summary['rows'], 'without_pdf'));
        self::assertSame(['policies' => 7, 'premium_cents' => 700, 'coverage_cents' => 7000, 'without_pdf' => 2], $summary['totals']);
        foreach (array_keys($summary['totals']) as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame([true, '2028-02-10', 2], [$summary['covered'], $summary['covered_until'], $summary['cancelled']], 'Cobertura emendada com a renovação.');

        $p = static fn (string $s, string $e): array => ['starts_on' => $s, 'ends_on' => $e];
        self::assertNull(InsuranceCoverage::coveredUntil([$p('2025-11-01', '2026-11-10')], $on), 'Descoberto: a única venceu.');
        self::assertNull(InsuranceCoverage::coveredUntil([$p('2026-11-12', '2027-11-11')], $on), 'Descoberto até a nova começar.');
        self::assertSame('2026-12-31', InsuranceCoverage::coveredUntil([$p('2026-01-01', '2026-12-31'), $p('2027-01-02', '2027-12-31')], $on), 'Um dia sem seguro.');
        self::assertSame('2027-12-31', InsuranceCoverage::coveredUntil([$p('2027-01-01', '2027-12-31'), $p('2026-01-01', '2026-12-31')], $on), 'Ordem não importa.');
        $empty = InsuranceCoverage::summarize([], $on);
        self::assertSame([5, 0, false, null], [count($empty['rows']), $empty['totals']['policies'], $empty['covered'], $empty['covered_until']]);
    }

    public function testBuildWithoutPolicyData(): void
    {
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (51, 1, NULL), (52, 1, '2026-10-01 00:00:00'), (53, 3, NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_insurances (condominium_id, insurer, policy_number, starts_on, ends_on, premium_cents, coverage_cents, document_id,
            notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'Seguradora Alfa', 'AP-001', '2025-11-11', '2026-12-31', 120000, 5000000, 51, 'Nota interna', 'active', null]);
        $insert->execute([1, 'Seguradora Beta', 'AP-002', '2026-01-01', '2027-03-31', 30000, 200000, 52, null, 'active', null]);    // PDF removido
        $insert->execute([1, 'Seguradora Gama', 'AP-003', '2025-01-01', '2026-01-01', 10000, 100000, 53, null, 'active', null]);    // PDF de outro condomínio
        $insert->execute([1, 'Seguradora Delta', 'AP-004', '2025-01-01', '2027-01-01', 99999, 99999, 51, null, 'inactive', null]);  // cancelada
        $insert->execute([1, 'Seguradora Épsilon', 'AP-005', '2025-01-01', '2027-01-01', 99999, 99999, null, null, 'active', '2026-01-01 00:00:00']);
        $insert->execute([3, 'Seguradora Fora', 'AP-006', '2025-01-01', '2027-01-01', 99999, 99999, 53, null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-11-11');
        self::assertSame([1, '2026-11-11', [1, 0, 1, 1, 0], 160000, 2, true, '2027-03-31', 1], [$report['condominium_id'], $report['on'],
            array_column($report['rows'], 'policies'), $report['totals']['premium_cents'], $report['totals']['without_pdf'], $report['covered'], $report['covered_until'],
            $report['cancelled']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Seguradora', 'AP-00', 'Nota interna', 'document_id', 'insurer', 'policy'] as $needle) {
            self::assertStringNotContainsString($needle, str_replace('"policies"', '', $json), 'Sem dado da apólice: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], [1, '11/11/2026'], [1, '2026-02-30']] as [$c, $on]) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/InsuranceCoverage.php');
        foreach (['$this->scope->assertAccess(', "i.deleted_at IS NULL AND i.status = 'active'", 'd.condominium_id = i.condominium_id AND d.deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'insurer', 'policy_number', 'supplier', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado da apólice: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/InsuranceCoverage.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.insurances.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/insurance-coverage", "InsuranceCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
