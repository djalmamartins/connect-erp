<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\TaxCoverage;

/**
 * ADR 0262: cobertura das alíquotas de retenção. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra
 * pura (a própria substitui a padrão, menor e maior alíquota, serviços e materiais, inativas, impostos em falta; total =
 * soma das linhas), a consulta sem nome nem observação, a validação, o escopo e o contrato; as alíquotas gravadas pelo
 * cadastro são conferidas no MariaDB (Integration\TaxCoverageTest).
 */
final class TaxCoverageTest extends TestCase
{
    private PDO $pdo;
    private TaxCoverage $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_taxes (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, name TEXT, rate REAL, applies_to TEXT, notes TEXT, status TEXT, deleted_at TEXT)']
            as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new TaxCoverage($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $t = static fn (string $owner, string $kind, float $rate, string $applies, string $status = 'active'): array => ['owner' => $owner, 'kind' => $kind, 'rate' => $rate,
            'applies_to' => $applies, 'status' => $status];
        $summary = TaxCoverage::summarize([$t('condominium', 'iss', 2.0, 'servicos'), $t('condominium', 'iss', 5.0, 'todos'), $t('default', 'iss', 3.0, 'servicos'),
            $t('default', 'irrf', 1.5, 'servicos'), $t('condominium', 'inss', 11.0, 'servicos', 'inactive'), $t('condominium', 'outro', 1.0, 'materiais')]);
        self::assertSame(['iss', 'irrf', 'inss', 'pis_cofins_csll', 'outro'], array_column($summary['rows'], 'kind'), 'Os impostos vêm do cadastro.');
        self::assertSame(['condominium', 'default', null, null, 'condominium'], array_column($summary['rows'], 'source'), 'A própria substitui a padrão.');
        self::assertSame([2, 1, 0, 0, 1], array_column($summary['rows'], 'rates'));
        self::assertSame([2.0, 1.5, null, null, 1.0], array_column($summary['rows'], 'min_rate'));
        self::assertSame([5.0, 1.5, null, null, 1.0], array_column($summary['rows'], 'max_rate'));
        self::assertSame([true, true, false, false, false], array_column($summary['rows'], 'covers_services'));
        self::assertSame([true, false, false, false, true], array_column($summary['rows'], 'covers_materials'), '"Todos" vale para serviços e materiais.');
        self::assertSame([true, false, false, false, false], array_column($summary['rows'], 'overrides_default'));
        self::assertSame([0, 0, 1, 0, 0], array_column($summary['rows'], 'inactive_own'));
        self::assertSame([false, false, true, true, false], array_column($summary['rows'], 'missing'), 'Inativa não cobre.');
        self::assertSame([['own' => 3, 'default' => 2, 'inactive_own' => 1, 'rates' => 4], ['inss', 'pis_cofins_csll']], [$summary['totals'], $summary['missing_kinds']]);
        foreach (TaxCoverage::COUNTS as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
    }

    public function testBuildWithoutNamesOrNotes(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_taxes (condominium_id, kind, name, rate, applies_to, notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'iss', 'ISS Ribeirão Preto', 2.0, 'servicos', 'Lei municipal X', 'active', null]);
        $insert->execute([null, 'iss', 'ISS padrão', 5.0, 'servicos', null, 'active', null]);
        $insert->execute([null, 'irrf', 'IRRF padrão', 1.5, 'servicos', null, 'active', null]);
        $insert->execute([3, 'inss', 'INSS de outro', 11.0, 'servicos', null, 'active', null]);
        $insert->execute([1, 'inss', 'INSS excluído', 11.0, 'servicos', null, 'active', '2026-01-01 00:00:00']);
        $report = $this->service->build($this->user(), '1');
        self::assertSame([1, ['own' => 1, 'default' => 2, 'inactive_own' => 0, 'rates' => 2], ['inss', 'pis_cofins_csll', 'outro'], 2.0], [$report['condominium_id'],
            $report['totals'], $report['missing_kinds'], $report['rows'][0]['max_rate']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Ribeirão', 'padrão"', 'Lei municipal', 'notes', '"name"'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem nome nem observação: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, 'x', 0, '1.5', ['1']] as $c) {
            try {
                $this->service->build($this->user(), $c);
                self::fail('Entrada inválida passou: ' . json_encode($c));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/TaxCoverage.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('taxes')", '(condominium_id = ? OR condominium_id IS NULL) AND deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'notes', 'SELECT name', ', name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem nome nem observação: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/TaxCoverage.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.settings.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/tax-coverage", "TaxCoverage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
