<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MeterDeactivation;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0299: relógios parados inativados em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO de
 * teste tira o FOR UPDATE) para a regra pura (o limite do período, do cadastro e da instalação), a validação, o escopo, as
 * recusas (uma por caso, tudo desfeito), a prévia, a inativação só dos relógios parados e sem leitura não cobrada (os com
 * leitura não cobrada contados à parte), sem apagar nem mexer em leitura, e o contrato. Os tipos vêm do cadastro. O MariaDB
 * confere com relógios de verdade (Integration\MeterDeactivationTest).
 */
final class MeterDeactivationTest extends TestCase
{
    private PDO $pdo;
    private MeterDeactivation $service;

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
            'CREATE TABLE erp_meters (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, kind TEXT, number TEXT, installed_on TEXT NULL, status TEXT,
                updated_by INTEGER, created_at TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_meter_readings (id INTEGER PRIMARY KEY, condominium_id INTEGER, meter_id INTEGER, competence TEXT, reading REAL, consumption REAL,
                amount_cents INTEGER, entry_id INTEGER NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b, $c] = self::kinds();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $meter = $this->pdo->prepare("INSERT INTO erp_meters (id, condominium_id, unit_id, kind, number, installed_on, status, updated_by, created_at, deleted_at)
            VALUES (?, ?, 41, ?, 'HID-998877', ?, ?, 3, ?, ?)");
        foreach ([[1, 1, $a, null, 'active', '2023-01-01 10:00:00', null], [2, 1, $b, null, 'active', '2023-01-01 10:00:00', null],
            [3, 1, $a, null, 'active', '2023-01-01 10:00:00', null], [4, 1, $c, null, 'active', '2023-01-01 10:00:00', null],
            [5, 1, $a, '2025-12-01', 'active', '2023-01-01 10:00:00', null], [6, 1, $a, null, 'active', '2025-11-30 23:00:00', null],
            [7, 1, $a, null, 'inactive', '2023-01-01 10:00:00', null], [8, 1, $a, null, 'active', '2023-01-01 10:00:00', '2026-01-01 10:00:00'],
            [9, 3, $a, null, 'active', '2023-01-01 10:00:00', null], [10, 1, $c, null, 'active', '2023-01-01 10:00:00', null]] as $row) {
            $meter->execute($row);
        }
        $reading = $this->pdo->prepare('INSERT INTO erp_meter_readings (condominium_id, meter_id, competence, reading, consumption, amount_cents, entry_id)
            VALUES (?, ?, ?, 1234.5, 12.5, ?, ?)');
        foreach ([[1, 1, '2025-11', 9999, 501], [1, 3, '2025-12', 9999, 502], [1, 4, '2024-05', 500, null], [1, 10, '2024-01', 0, null], [3, 9, '2020-01', 100, null]] as $row) {
            $reading->execute($row);
        }
        $this->service = new MeterDeactivation($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string, 2:string} os três primeiros tipos, vindos do cadastro */
    private static function kinds(): array
    {
        $kinds = array_keys(MeterDeactivation::kinds());
        self::assertGreaterThanOrEqual(3, count($kinds));
        return [$kinds[0], $kinds[1], $kinds[2]];
    }

    public function testProblemRule(): void
    {
        $old = ['status' => 'active', 'since' => '2023-01-01'];
        self::assertNull(MeterDeactivation::problem($old, null, 0, '2025-12', 12), 'Nunca teve leitura.');
        self::assertNull(MeterDeactivation::problem($old, '2025-11', 0, '2025-12', 12), 'Um mês antes do período.');
        self::assertNotNull(MeterDeactivation::problem($old, '2025-12', 0, '2025-12', 12), 'O primeiro mês do período conta.');
        self::assertNull(MeterDeactivation::problem(['since' => '2025-11-30'] + $old, null, 0, '2025-12', 12), 'Um dia antes do período.');
        self::assertNotNull(MeterDeactivation::problem(['since' => '2025-12-01'] + $old, null, 0, '2025-12', 12), 'No primeiro dia do período.');
        self::assertNotNull(MeterDeactivation::problem($old, null, 1, '2025-12', 12));
        self::assertNotNull(MeterDeactivation::problem(['status' => 'inactive'] + $old, null, 0, '2025-12', 12));
        self::assertNotNull(MeterDeactivation::problem(null, null, 0, '2025-12', 12));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Relógio retirado'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['months' => (string)(MeterDeactivation::MIN_MONTHS - 1)] + $ok, ['months' => (string)(MeterDeactivation::MAX_MONTHS + 1)] + $ok, ['months' => 'x'] + $ok,
            ['meter_ids' => []] + $ok, ['meter_ids' => 'x'] + $ok, ['meter_ids' => ['a']] + $ok,
            ['meter_ids' => range(1, MeterDeactivation::MAX_METERS + 1)] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(), $input, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('active', $this->meterStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->deactivate($this->user(), ['condominium_id' => 3] + $ok, '2026-11-14');
    }

    public function testDryRunThenDeactivateTheIdleOnes(): void
    {
        [$a, $b, $c] = self::kinds();
        $input = ['condominium_id' => '1', 'reason' => 'Relógio retirado', 'dry_run' => true];
        $preview = $this->service->deactivate($this->user(), $input, '2026-11-14');
        self::assertSame([[1, 2, 6, 10], 1, 0, true, '2025-12', '2026-11', 12], [array_column($preview['meters'], 'id'), $preview['skipped_unbilled'], $preview['changed'],
            $preview['dry_run'], $preview['from'], $preview['to'], $preview['months']], 'O parado com leitura não cobrada fica de fora e é contado.');
        self::assertSame(['2025-11', null, null, '2024-01'], array_column($preview['meters'], 'last_competence'), 'A leitura de valor zero não espera cobrança.');
        self::assertSame('active', $this->meterStatus(1), 'A prévia desfaz.');

        $done = $this->service->deactivate($this->user(), ['dry_run' => false] + $input, '2026-11-14');
        self::assertSame([4, 4], [$done['count'], $done['changed']]);
        $byKind = array_combine(array_column($done['by_kind'], 'kind'), array_column($done['by_kind'], 'meters'));
        self::assertSame([2, 1, 1], [$byKind[$a], $byKind[$b], $byKind[$c]]);
        self::assertSame($done['count'], array_sum($byKind), 'O total é a soma dos tipos.');
        foreach ([1, 2, 6, 10] as $id) {
            self::assertSame('inactive', $this->meterStatus($id), "Relógio {$id}.");
        }
        foreach ([3, 4, 5, 9] as $id) {
            self::assertSame('active', $this->meterStatus($id), "Relógio {$id}: com leitura, não cobrado, instalado no período ou de outro condomínio ficam.");
        }
        self::assertSame(7, (int)$this->pdo->query('SELECT updated_by FROM erp_meters WHERE id = 1')->fetchColumn());
        self::assertSame([10, 5, 2], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_meters')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_meter_readings')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_meter_readings WHERE entry_id IS NOT NULL')->fetchColumn()], 'Nada é apagado e nenhuma leitura muda.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['HID-', '998877', '1234', '9999', 'retirado', 'number', 'unit', 'reading', 'amount'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach ([3 => 'teve leitura', 4 => 'não cobrada', 5 => 'cadastrado ou instalado', 7 => 'já está inativo', 8 => 'não encontrado', 9 => 'não encontrado',
            999 => 'não encontrado'] as $bad => $text) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Relógio retirado', 'meter_ids' => [1, $bad]], '2026-11-14');
                self::fail("O relógio {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_meter']);
                self::assertStringContainsString($text, $exception->errors['_form']);
            }
            self::assertSame('active', $this->meterStatus(1), 'Tudo ou nada.');
        }
        $done = $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Relógio retirado', 'meter_ids' => ['2'], 'months' => '3'], '2026-11-14');
        self::assertSame([[2], 'active'], [array_column($done['meters'], 'id'), $this->meterStatus(1)], 'Com ids, só os informados.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame('erp_meters', RegistryService::definition('meters')['table']);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0527_meter_readings.sql');
        self::assertMatchesRegularExpression('/UNIQUE KEY `uq_erp_meter_readings_month` \(`meter_id`, `competence`\)/', $sql, 'Uma leitura por relógio e competência.');
        $readings = (string)file_get_contents($root . '/source/Services/Erp/MeterReadings.php');
        self::assertMatchesRegularExpression("/WHERE m\\.id = :id AND m\\.deleted_at IS NULL AND m\\.status = 'active'/", $readings, 'A leitura só aceita relógio ativo.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/MeterDeactivation.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('meters')", 'DiscountOverview::months(', 'SELECT NOW()', 'FOR UPDATE',
            'entry_id IS NULL AND amount_cents > 0', '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', 'Audit::record(',
            "AND status = 'active'", 'rowCount() !== 1'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'UPDATE erp_meter_readings', 'INSERT ', 'number', 'unit_id', 'consumption', 'SELECT *'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar, sem mexer em leitura e sem número, unidade ou consumo: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MeterDeactivation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can(\$meters . '.view', \$user)", "Access::can(\$meters . '.manage', \$user)",
            "Access::can('erp.meter_readings.view', \$user)", 'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/meters/deactivate-idle", "MeterDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/bank-accounts"'), strpos($index, $route), 'Logo depois do relatório das contas bancárias.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/bank-accounts:\n"), strpos($openapi, "  /meters/deactivate-idle:\n"));
    }

    private function meterStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_meters WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
