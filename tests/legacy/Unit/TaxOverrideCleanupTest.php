<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\TaxCoverage;
use Source\Services\Erp\TaxOverrideCleanup;

/**
 * ADR 0293: alíquotas próprias que só repetem o padrão inativadas em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura (conjunto de taxa e aplicação, com repetidas), a
 * validação, o escopo, as recusas (uma por caso, tudo desfeito), a prévia, a inativação só das próprias do imposto
 * redundante, sem tocar o padrão nem outro condomínio, a cobertura da 0262 igual antes e depois, e o contrato. Os impostos
 * vêm do campo kind do cadastro. O MariaDB confere com alíquotas de verdade (Integration\TaxOverrideCleanupTest).
 */
final class TaxOverrideCleanupTest extends TestCase
{
    private PDO $pdo;
    private TaxOverrideCleanup $service;

    protected function setUp(): void
    {
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_taxes (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, kind TEXT, name TEXT, rate REAL, applies_to TEXT, notes TEXT, status TEXT,
                updated_by INTEGER, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $insert = $this->pdo->prepare("INSERT INTO erp_taxes (id, condominium_id, kind, name, rate, applies_to, notes, status, updated_by, deleted_at)
            VALUES (?, ?, ?, 'ISS do Fulano', ?, ?, 'Contador Fulano', ?, 3, ?)");
        foreach ([[1, 1, 'iss', 5.00, 'servicos', 'active', null], [10, null, 'iss', 5.00, 'servicos', 'active', null],
            [2, 1, 'irrf', 1.50, 'todos', 'active', null], [3, 1, 'irrf', 1.50, 'todos', 'active', null], [11, null, 'irrf', 1.50, 'todos', 'active', null],
            [4, 1, 'inss', 11.00, 'servicos', 'active', null], [12, null, 'inss', 11.00, 'materiais', 'active', null],
            [5, 1, 'pis_cofins_csll', 4.65, 'servicos', 'inactive', null], [13, null, 'pis_cofins_csll', 4.65, 'servicos', 'active', null],
            [6, 1, 'outro', 2.00, 'todos', 'active', null], [7, 1, 'iss', 9.00, 'servicos', 'active', '2026-11-01 10:00:00'],
            [8, 3, 'iss', 5.00, 'servicos', 'active', null]] as $row) {
            $insert->execute($row);
        }
        $this->service = new TaxOverrideCleanup($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testRedundantAndProblemRules(): void
    {
        $t = static fn (float $rate, string $applies): array => ['rate' => $rate, 'applies_to' => $applies];
        self::assertTrue(TaxOverrideCleanup::redundant([$t(5, 'servicos'), $t(2, 'materiais')], [$t(2.0, 'materiais'), $t(5.004, 'servicos')]), 'A ordem não importa.');
        self::assertFalse(TaxOverrideCleanup::redundant([$t(1.5, 'todos'), $t(1.5, 'todos')], [$t(1.5, 'todos')]), 'Repetida conta.');
        self::assertFalse(TaxOverrideCleanup::redundant([$t(11, 'servicos')], [$t(11, 'materiais')]), 'Aplicação diferente.');
        self::assertFalse(TaxOverrideCleanup::redundant([$t(5, 'servicos')], [$t(5.01, 'servicos')]), 'Taxa diferente.');
        self::assertFalse(TaxOverrideCleanup::redundant([], []));
        self::assertNull(TaxOverrideCleanup::problem('ISS', [$t(5, 'servicos')], [$t(5, 'servicos')]));
        foreach ([[null, [$t(5, 'servicos')], [$t(5, 'servicos')]], ['ISS', [], [$t(5, 'servicos')]], ['ISS', [$t(5, 'servicos')], []],
            ['ISS', [$t(5, 'servicos')], [$t(6, 'servicos')]]] as [$label, $own, $default]) {
            self::assertNotNull(TaxOverrideCleanup::problem($label, $own, $default));
        }
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Voltar ao padrão'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['kinds' => []] + $ok, ['kinds' => 'iss'] + $ok, ['kinds' => [1]] + $ok, ['kinds' => ['']] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('active', $this->taxStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->deactivate($this->user(), ['condominium_id' => 3] + $ok);
    }

    public function testDryRunThenDeactivateTheRedundantKind(): void
    {
        $coverage = new TaxCoverage($this->pdo, new CondominiumScope($this->pdo));
        $before = array_combine(array_column($coverage->build($this->user(), 1)['rows'], 'kind'), $coverage->build($this->user(), 1)['rows']);
        self::assertSame(['condominium', true], [$before['iss']['source'], $before['iss']['overrides_default']]);

        $input = ['condominium_id' => '1', 'reason' => 'Voltar ao padrão', 'dry_run' => true];
        $preview = $this->service->deactivate($this->user(), $input);
        self::assertSame([['iss'], [[1]], 1, 0, true], [array_column($preview['kinds'], 'kind'), array_column($preview['kinds'], 'tax_ids'), $preview['count'],
            $preview['changed'], $preview['dry_run']], 'Só o imposto em que as próprias repetem o padrão.');
        self::assertSame([['rate' => 5.0, 'applies_to' => 'servicos']], $preview['kinds'][0]['rates']);
        self::assertSame('active', $this->taxStatus(1), 'A prévia desfaz.');

        $done = $this->service->deactivate($this->user(), ['dry_run' => false] + $input);
        self::assertSame([1, 1], [$done['count'], $done['changed']]);
        self::assertSame(['inactive', 'active', 'active', 'active', 'active', 'active'], [$this->taxStatus(1), $this->taxStatus(10), $this->taxStatus(2),
            $this->taxStatus(4), $this->taxStatus(6), $this->taxStatus(8)], 'O padrão, os outros impostos e o outro condomínio ficam.');
        self::assertSame([7, null], [(int)$this->pdo->query('SELECT updated_by FROM erp_taxes WHERE id = 1')->fetchColumn(),
            $this->pdo->query('SELECT deleted_at FROM erp_taxes WHERE id = 1')->fetchColumn() ?: null], 'Só o status e quem alterou; nada é apagado.');
        $after = array_combine(array_column($coverage->build($this->user(), 1)['rows'], 'kind'), $coverage->build($this->user(), 1)['rows']);
        self::assertSame(['default', false], [$after['iss']['source'], $after['iss']['overrides_default']], 'Volta a seguir o padrão.');
        foreach (['min_rate', 'max_rate', 'covers_services', 'covers_materials', 'missing'] as $key) {
            self::assertSame($before['iss'][$key], $after['iss'][$key], "A alíquota em vigor não muda: {$key}.");
        }
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Contador', 'name', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $again = $this->service->deactivate($this->user(), $input);
        self::assertSame(0, $again['count'], 'Sem imposto redundante, nada entra.');
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach (['irrf' => 'diferentes', 'inss' => 'diferentes', 'pis_cofins_csll' => 'não tem alíquota própria', 'outro' => 'Não há alíquota padrão',
            'tipo_novo' => 'desconhecido'] as $bad => $text) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Voltar ao padrão', 'kinds' => ['iss', $bad]]);
                self::fail("O imposto {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame($bad, $exception->errors['_kind']);
                self::assertStringContainsString($text, $exception->errors['_form']);
            }
            self::assertSame('active', $this->taxStatus(1), 'Tudo ou nada.');
        }
        $done = $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Voltar ao padrão', 'kinds' => ['iss', 'iss']]);
        self::assertSame([['iss'], 1], [array_column($done['kinds'], 'kind'), $done['count']], 'Com kinds, só os informados, sem repetir.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $definition = RegistryService::definition('taxes');
        self::assertSame(['erp_taxes', 'erp.settings', 'optional'], [$definition['table'], $definition['permission'], $definition['scope']]);
        $coverage = (string)file_get_contents($root . '/source/Services/Erp/TaxCoverage.php');
        self::assertStringContainsString("\$own !== [] => ['condominium', \$own]", $coverage, 'A regra de origem da cobertura: havendo própria, valem só as próprias.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/TaxOverrideCleanup.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('taxes')", "['fields']['kind']['options']", 'FOR UPDATE',
            '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', 'Audit::record(', "AND status = 'active'", 'rowCount() !== 1',
            'AND condominium_id = ? AND status'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'name', 'notes', 'condominium_id IS NULL AND status'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar, sem nome nem observação, sem tocar o padrão: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/TaxOverrideCleanup.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can(\$permission . '.view', \$user)", "Access::can(\$permission . '.manage', \$user)",
            "RegistryService::definition('taxes')['permission']", 'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/taxes/deactivate-redundant", "TaxOverrideCleanup:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/unit-documents"'), strpos($index, $route), 'Logo depois do relatório de documentos.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/unit-documents:\n"), strpos($openapi, "  /taxes/deactivate-redundant:\n"));
    }

    private function taxStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_taxes WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
