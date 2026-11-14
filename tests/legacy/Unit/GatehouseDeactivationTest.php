<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\GatehouseDeactivation;
use Source\Services\Erp\GatehouseOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0295: portarias paradas inativadas em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de
 * teste tira o FOR UPDATE) para a regra pura (o limite da janela), a validação, o escopo, as recusas (uma por caso, tudo
 * desfeito), a prévia, a inativação só das portarias paradas e sem encomenda aguardando (as com encomenda contadas à
 * parte), sem apagar nem mexer em acesso ou encomenda, a situação da 0294 depois e o contrato. Os tipos vêm do cadastro. O
 * MariaDB confere com portarias de verdade (Integration\GatehouseDeactivationTest).
 */
final class GatehouseDeactivationTest extends TestCase
{
    private PDO $pdo;
    private GatehouseDeactivation $service;

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
            'CREATE TABLE erp_gatehouses (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT, manager_name TEXT, phone TEXT, status TEXT, updated_by INTEGER,
                deleted_at TEXT NULL)',
            'CREATE TABLE erp_access_logs (id INTEGER PRIMARY KEY, condominium_id INTEGER, person_name TEXT, entered_on TEXT, gatehouse_id INTEGER NULL, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_deliveries (id INTEGER PRIMARY KEY, condominium_id INTEGER, recipient TEXT, received_on TEXT, gatehouse_id INTEGER NULL, status TEXT, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b] = self::kinds();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $gate = $this->pdo->prepare("INSERT INTO erp_gatehouses (id, condominium_id, name, kind, manager_name, phone, status, updated_by, deleted_at)
            VALUES (?, ?, 'Guarita do Fulano', ?, 'Fulano de Tal', '11999990000', ?, 3, ?)");
        foreach ([[1, 1, $a, 'active', null], [2, 1, $b, 'active', null], [3, 1, $a, 'active', null], [4, 1, $a, 'active', null], [5, 1, $a, 'inactive', null],
            [6, 1, $a, 'active', null], [7, 1, $b, 'active', null], [8, 1, $a, 'active', '2026-10-01 10:00:00'], [9, 3, $a, 'active', null]] as $row) {
            $gate->execute($row);
        }
        $log = $this->pdo->prepare("INSERT INTO erp_access_logs (condominium_id, person_name, entered_on, gatehouse_id, status, deleted_at) VALUES (?, 'Visitante Fulano', ?, ?, ?, ?)");
        foreach ([[1, '2026-05-01', 1, 'active', null], [1, '2026-11-01', 3, 'active', null], [1, '2026-05-01', 4, 'active', null], [1, '2026-08-17', 6, 'active', null],
            [1, '2026-08-16', 7, 'active', null], [1, '2026-11-01', 7, 'inactive', null], [1, '2026-11-02', 7, 'active', '2026-11-03 10:00:00']] as $row) {
            $log->execute($row);
        }
        $delivery = $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, recipient, received_on, gatehouse_id, status, deleted_at) VALUES (1, 'Morador Fulano', ?, ?, ?, NULL)");
        foreach ([['2026-10-01', 4, 'active'], ['2026-10-01', 1, 'picked_up']] as $row) {
            $delivery->execute($row);
        }
        $this->service = new GatehouseDeactivation($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} os dois primeiros tipos, vindos do cadastro */
    private static function kinds(): array
    {
        $kinds = array_keys(GatehouseOverview::kinds());
        self::assertGreaterThanOrEqual(2, count($kinds));
        return [$kinds[0], $kinds[1]];
    }

    public function testProblemRule(): void
    {
        $active = ['status' => 'active'];
        self::assertNull(GatehouseDeactivation::problem($active, null, 0, '2026-08-17', 90), 'Nunca registrou acesso.');
        self::assertNull(GatehouseDeactivation::problem($active, '2026-08-16', 0, '2026-08-17', 90), 'Um dia antes da janela.');
        self::assertNotNull(GatehouseDeactivation::problem($active, '2026-08-17', 0, '2026-08-17', 90), 'O primeiro dia da janela conta.');
        self::assertNotNull(GatehouseDeactivation::problem($active, null, 1, '2026-08-17', 90));
        self::assertNotNull(GatehouseDeactivation::problem(['status' => 'inactive'], null, 0, '2026-08-17', 90));
        self::assertNotNull(GatehouseDeactivation::problem(null, null, 0, '2026-08-17', 90));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Guarita desativada'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['days' => (string)(GatehouseDeactivation::MIN_DAYS - 1)] + $ok, ['days' => (string)(GatehouseDeactivation::MAX_DAYS + 1)] + $ok, ['days' => 'x'] + $ok,
            ['gatehouse_ids' => []] + $ok, ['gatehouse_ids' => 'x'] + $ok, ['gatehouse_ids' => ['a']] + $ok,
            ['gatehouse_ids' => range(1, GatehouseDeactivation::MAX_GATEHOUSES + 1)] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(), $input, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('active', $this->gateStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->deactivate($this->user(), ['condominium_id' => 3] + $ok, '2026-11-14');
    }

    public function testDryRunThenDeactivateTheIdleOnes(): void
    {
        [$a, $b] = self::kinds();
        $input = ['condominium_id' => '1', 'reason' => 'Guarita desativada', 'dry_run' => true];
        $preview = $this->service->deactivate($this->user(), $input, '2026-11-14');
        self::assertSame([[1, 2, 7], 1, 0, true, '2026-08-17', 90], [array_column($preview['gatehouses'], 'id'), $preview['skipped_pending'], $preview['changed'],
            $preview['dry_run'], $preview['from'], $preview['days']], 'A parada com encomenda aguardando fica de fora e é contada.');
        self::assertSame(['2026-05-01', null, '2026-08-16'], array_column($preview['gatehouses'], 'last_entry_on'), 'O acesso cancelado e o excluído não contam.');
        self::assertSame('active', $this->gateStatus(1), 'A prévia desfaz.');

        $done = $this->service->deactivate($this->user(), ['dry_run' => false] + $input, '2026-11-14');
        self::assertSame([3, 3], [$done['count'], $done['changed']]);
        $byKind = array_combine(array_column($done['by_kind'], 'kind'), array_column($done['by_kind'], 'gatehouses'));
        self::assertSame([1, 2], [$byKind[$a], $byKind[$b]]);
        self::assertSame($done['count'], array_sum($byKind), 'O total é a soma dos tipos.');
        self::assertSame(['inactive', 'inactive', 'inactive', 'active', 'active', 'active', 'active'], [$this->gateStatus(1), $this->gateStatus(2), $this->gateStatus(7),
            $this->gateStatus(3), $this->gateStatus(4), $this->gateStatus(6), $this->gateStatus(9)], 'Com acesso, com encomenda, no limite e de outro condomínio ficam.');
        self::assertSame(7, (int)$this->pdo->query('SELECT updated_by FROM erp_gatehouses WHERE id = 1')->fetchColumn());
        self::assertSame([7, 2], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_access_logs')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_deliveries')->fetchColumn()], 'Nada é apagado.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Guarita', 'desativada', '11999', 'name', 'phone'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $report = (new GatehouseOverview($this->pdo, new CondominiumScope($this->pdo)))->build($this->user(), 1, null, '2026-11-14');
        self::assertSame(4, $report['totals']['inactive'], 'Na situação da 0294, as inativadas saem das ativas.');
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach ([3 => 'registrou acesso', 6 => 'registrou acesso', 4 => 'encomenda aguardando', 5 => 'já está inativa', 8 => 'não encontrada', 9 => 'não encontrada',
            999 => 'não encontrada'] as $bad => $text) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Guarita desativada', 'gatehouse_ids' => [1, $bad]], '2026-11-14');
                self::fail("A portaria {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_gatehouse']);
                self::assertStringContainsString($text, $exception->errors['_form']);
            }
            self::assertSame('active', $this->gateStatus(1), 'Tudo ou nada.');
        }
        $done = $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Guarita desativada', 'gatehouse_ids' => ['2'], 'days' => '30'], '2026-11-14');
        self::assertSame([[2], 'active'], [array_column($done['gatehouses'], 'id'), $this->gateStatus(1)], 'Com ids, só as informadas.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/GatehouseDeactivation.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('gatehouses')", "RegistryService::definition('access_logs')",
            "RegistryService::definition('deliveries')", 'GatehouseOverview::kinds()', 'SELECT NOW()', 'FOR UPDATE', '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()',
            'if (!$dryRun)', 'Audit::record(', "AND status = 'active'", 'rowCount() !== 1'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'manager_name', 'phone', 'person_', 'recipient', 'SELECT id, name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar e sem nome, responsável, telefone ou pessoa: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/GatehouseDeactivation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can(\$gatehouses . '.view', \$user)", "Access::can(\$gatehouses . '.manage', \$user)",
            "['access_logs', 'deliveries']", 'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/gatehouses/deactivate-idle", "GatehouseDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/gatehouses"'), strpos($index, $route), 'Logo depois do relatório.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/gatehouses:\n"), strpos($openapi, "  /gatehouses/deactivate-idle:\n"));
    }

    private function gateStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_gatehouses WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
