<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ImportDiscard;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\ImportsOverview;
use Source\Services\Erp\NotificationStaleCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0285: prévias de importação paradas descartadas em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE e ganha NOW()) para a regra pura, a validação, o escopo, as recusas (uma por caso,
 * tudo desfeito, sem nome de arquivo nem hash na mensagem), a prévia, o limite em dias, o descarte gravado só nas prévias
 * paradas deste condomínio, sem apagar arquivo nem aplicar nada, a situação nova no relatório da 0284 e o contrato. Os
 * tipos vêm de Imports::KINDS. O MariaDB confere com a migration e o Imports de verdade (Integration\ImportDiscardTest).
 */
final class ImportDiscardTest extends TestCase
{
    private PDO $pdo;
    private ImportDiscard $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $now = static fn (): string => '2026-11-14 12:00:00';
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->createFunction('NOW', $now, 0);
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_imports (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, original_name TEXT, sha256 TEXT, storage_path TEXT, status TEXT,
                rows_count INTEGER, error_count INTEGER, created_at TEXT, applied_at TEXT, discarded_by INTEGER, discarded_at TEXT, discard_reason TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $kinds = array_keys(Imports::KINDS);
        [$a, $b] = [$kinds[0], $kinds[count($kinds) - 1]];
        $insert = $this->pdo->prepare("INSERT INTO erp_imports (id, condominium_id, kind, original_name, sha256, storage_path, status, rows_count, error_count, created_at)
            VALUES (?, ?, ?, 'extrato-fulano.ofx', 'abc123hash', 'storage/private/imports/1/x.ofx', ?, 3, 0, ?)");
        foreach ([[1, 1, $a, 'previewed', '2026-10-01 09:00:00'], [2, 1, $a, 'previewed', '2026-11-10 09:00:00'], [3, 1, $a, 'applied', '2026-09-01 09:00:00'],
            [4, 1, $a, 'discarded', '2026-09-01 09:00:00'], [5, 1, $b, 'previewed', '2026-08-01 09:00:00'], [6, 3, $a, 'previewed', '2026-01-01 09:00:00']] as $row) {
            $insert->execute($row);
        }
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->service = new ImportDiscard($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $cutoff = NotificationStaleCancellation::cutoff($this->now, ImportDiscard::DEFAULT_DAYS);
        self::assertLessThan($this->now, $cutoff, 'O limite fica no passado.');
        $row = ['status' => 'previewed', 'created_at' => '2026-01-01 00:00:00'];
        self::assertNull(ImportDiscard::problem($row, $cutoff, 30));
        self::assertStringContainsString('não encontrada', (string)ImportDiscard::problem(null, $cutoff, 30));
        self::assertStringContainsString('não está em prévia', (string)ImportDiscard::problem(['status' => 'applied'] + $row, $cutoff, 30));
        self::assertStringContainsString('não está em prévia', (string)ImportDiscard::problem(['status' => 'discarded'] + $row, $cutoff, 30));
        self::assertStringContainsString('mais nova que o limite de 30 dias', (string)ImportDiscard::problem(['created_at' => '2026-11-14 00:00:00'] + $row, $cutoff, 30));
        self::assertArrayHasKey('discarded', ImportsOverview::STATUSES, 'O relatório da 0284 conhece a situação nova.');
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Prévias esquecidas'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('x', 256)] + $ok, ['days' => 0] + $ok, ['days' => 'x'] + $ok,
            ['days' => ImportDiscard::MAX_DAYS + 1] + $ok, ['import_ids' => []] + $ok, ['import_ids' => ['x']] + $ok, ['import_ids' => 'abc'] + $ok,
            ['import_ids' => range(1, ImportDiscard::MAX_IMPORTS + 1)] + $ok] as $input) {
            try {
                $this->service->discard($this->user(), $input, $this->now);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->discard($this->user(), ['condominium_id' => 3] + $ok, $this->now);
    }

    public function testChosenIdsRefusedAllOrNothing(): void
    {
        foreach ([[2, 'mais nova'], [3, 'não está em prévia'], [4, 'não está em prévia'], [6, 'não encontrada'], [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->discard($this->user(), ['condominium_id' => 1, 'import_ids' => [1, $id], 'reason' => 'Prévias esquecidas'], $this->now);
                self::fail("Importação {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_import'], 'A 1 é prévia parada e passa; a recusa é a da outra.');
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['extrato', 'fulano', 'abc123hash', 'storage'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(1, $this->discarded(), 'Nada mais foi descartado (só a 4, de antes).');
        }
    }

    public function testDryRunDaysAndDiscardWithoutIds(): void
    {
        [$a, $b] = [array_key_first(Imports::KINDS), array_key_last(Imports::KINDS)];
        $preview = $this->service->discard($this->user(), ['condominium_id' => '1', 'reason' => 'Prévias esquecidas', 'dry_run' => true], $this->now);
        self::assertSame([1, 5], array_column($preview['imports'], 'id'), 'Só as prévias paradas; a recente, a aplicada, a descartada e a de fora ficam.');
        self::assertSame([2, 0, ImportDiscard::DEFAULT_DAYS], [$preview['count'], $preview['changed'], $preview['days']]);
        self::assertSame(array_keys(Imports::KINDS), array_column($preview['by_kind'], 'kind'), 'Todos os tipos, sempre.');
        self::assertSame($preview['count'], array_sum(array_column($preview['by_kind'], 'imports')), 'O total é a soma por tipo.');
        self::assertSame(1, $this->discarded(), 'A prévia desfaz tudo.');
        $longer = $this->service->discard($this->user(), ['condominium_id' => 1, 'days' => 60, 'reason' => 'Prévias esquecidas', 'dry_run' => true], $this->now);
        self::assertSame([5], array_column($longer['imports'], 'id'), 'Com 60 dias, só a de agosto.');

        $result = $this->service->discard($this->user(), ['condominium_id' => 1, 'reason' => 'Prévias esquecidas'], $this->now);
        self::assertSame([2, 2], [$result['count'], $result['changed']]);
        $rows = $this->pdo->query('SELECT id, status, discarded_by, discard_reason, storage_path FROM erp_imports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_combine(array_map('intval', array_column($rows, 'id')), $rows);
        foreach ([1, 5] as $id) {
            self::assertSame(['discarded', 7, 'Prévias esquecidas'], [$byId[$id]['status'], (int)$byId[$id]['discarded_by'], $byId[$id]['discard_reason']]);
            self::assertSame('storage/private/imports/1/x.ofx', $byId[$id]['storage_path'], 'O arquivo fica guardado.');
        }
        self::assertSame(['previewed', 'applied', 'discarded', 'previewed'], [$byId[2]['status'], $byId[3]['status'], $byId[4]['status'], $byId[6]['status']]);
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['extrato', 'fulano', 'abc123hash', 'storage'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame($a === $b ? 2 : 1, array_column($result['by_kind'], 'imports', 'kind')[$a]);
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ImportDiscard.php');
        foreach ([' FOR UPDATE', "Audit::record('discard'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "AND status = 'previewed'", 'Imports::KINDS',
            'NotificationStaleCancellation::cutoff(', 'SELECT NOW()'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'DELETE ', 'unlink', '->apply(', 'original_name', 'storage_path', 'sha256', 'summary_json'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só descarta, sem apagar arquivo nem aplicar e sem arquivo, caminho ou hash: ' . $needle);
        }
        $imports = (string)file_get_contents($root . '/source/Services/Erp/Imports/Imports.php');
        self::assertStringContainsString("SET status = 'previewed', discarded_by = NULL", $imports, 'Enviar de novo volta a descartada para prévia.');
        self::assertStringContainsString("\$import['status'] === 'discarded'", $imports, 'A descartada não é aplicada.');
        $screen = (string)file_get_contents($root . '/container/erp/connect/v1/pages/imports/import.php');
        self::assertStringContainsString("['Descartada', 'plain']", $screen, 'A tela de importações mostra a descartada como descartada.');
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ImportDiscard.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.imports.view', \$user)", "Access::can('erp.imports.manage', \$user)",
            'catch (CondominiumAccessDenied | RegistryNotFound)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/imports/discard-stale", "ImportDiscard:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/imports"'), strpos($index, $route), 'Logo depois da situação das importações.');
        self::assertLessThan(strpos($index, '"/imports/{id}"'), strpos($index, $route), 'Antes das rotas /imports/{id}.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/imports:\n"), strpos($openapi, "  /imports/discard-stale:\n"));
        foreach (['20261114_0001_import_discard.sql', '20261114_0001_import_discard.down.sql'] as $file) {
            self::assertFileExists($root . '/database/migrations/' . $file, 'Migration com reversão.');
        }
    }

    private function discarded(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_imports WHERE status = 'discarded'")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
