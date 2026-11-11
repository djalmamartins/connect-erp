<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MeterReadingReview;

/**
 * ADR 0220: conferência das leituras de consumo, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para a regra pura (inclusive histórico curto), a montagem, a validação e o escopo; as leituras gravadas pelo
 * MeterReadings::record são conferidas no MariaDB (Integration\MeterReadingReviewTest).
 */
final class MeterReadingReviewTest extends TestCase
{
    private PDO $pdo;
    private MeterReadingReview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT)',
            'CREATE TABLE erp_meters (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, kind TEXT, number TEXT, initial_reading REAL, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_meter_readings (id INTEGER PRIMARY KEY, condominium_id INTEGER, meter_id INTEGER, competence TEXT, previous_reading REAL, reading REAL,
                consumption REAL, entry_id INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new MeterReadingReview($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testClassifyPureRule(): void
    {
        $now = static fn (float $previous, float $consumption): array => ['previous_reading' => $previous, 'consumption' => $consumption];
        self::assertSame(['issues' => ['missing'], 'average' => null, 'ratio' => null], MeterReadingReview::classify(null, [], 0.0));
        self::assertSame(['issues' => [], 'average' => null, 'ratio' => null], MeterReadingReview::classify($now(20, 90), [10.0, 10.0], 20.0),
            'Histórico curto: nada por média.');
        self::assertSame(['issues' => ['spike'], 'average' => 10.0, 'ratio' => 3.0], MeterReadingReview::classify($now(20, 30), [10.0, 10.0, 10.0], 20.0));
        self::assertSame(['drop'], MeterReadingReview::classify($now(20, 4), [10.0, 10.0, 10.0], 20.0)['issues']);
        self::assertSame([], MeterReadingReview::classify($now(20, 25), [10.0, 10.0, 10.0], 20.0, 3.0)['issues'], 'Fator 3: 2,5× não é pico.');
        self::assertSame(['sequence_break', 'zero'], MeterReadingReview::classify($now(21, 0), [10.0, 10.0, 10.0], 20.0)['issues']);
    }

    public function testBuildFlagsBeforeBilling(): void
    {
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label) VALUES (11, 1, 'A-101'), (12, 1, 'A-102'), (13, 1, 'A-103'), (14, 1, 'A-104'),
            (15, 1, 'A-105'), (16, 1, 'A-106'), (17, 1, 'A-107'), (18, 3, 'Z-1')");
        $this->pdo->exec("INSERT INTO erp_meters (id, condominium_id, unit_id, kind, number, initial_reading, status) VALUES
            (1, 1, 11, 'agua', 'M1', 100, 'active'), (2, 1, 12, 'agua', 'M2', 0, 'active'), (3, 1, 13, 'gas', 'M3', 0, 'active'),
            (4, 1, 14, 'agua', 'M4', 0, 'active'), (5, 1, 15, 'agua', 'M5', 100, 'active'), (6, 1, 16, 'energia', 'M6', 0, 'active'),
            (7, 1, 17, 'agua', 'M7', 0, 'inactive'), (8, 3, 18, 'agua', 'M8', 0, 'active')");
        $rows = [];
        foreach (['2026-06' => 110, '2026-07' => 120, '2026-08' => 130, '2026-09' => 140, '2026-10' => 150] as $competence => $reading) {
            $rows[] = [1, $competence, $reading - 10, $reading, 10, null];
        }
        $rows[] = [1, '2026-11', 150, 180, 30, null];                                                  // pico (3×)
        foreach (['2026-08' => 10, '2026-09' => 20, '2026-10' => 30] as $competence => $reading) {
            $rows[] = [2, $competence, $reading - 10, $reading, 10, null];
        }
        $rows[] = [2, '2026-11', 30, 40, 10, 99];                                                      // normal e cobrada
        $rows[] = [3, '2026-10', 0, 20, 20, null];
        $rows[] = [3, '2026-11', 20, 70, 50, null];                                                    // histórico curto
        $rows[] = [5, '2026-11', 100, 100, 0, null];                                                   // zero
        $rows[] = [6, '2026-10', 460, 480, 20, null];
        $rows[] = [6, '2026-11', 500, 520, 20, null];                                                  // não continua da anterior
        $rows[] = [8, '2026-11', 0, 999, 999, null];                                                   // outro condomínio
        $stmt = $this->pdo->prepare('INSERT INTO erp_meter_readings (condominium_id, meter_id, competence, previous_reading, reading, consumption, entry_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as [$meter, $competence, $previous, $reading, $consumption, $entry]) {
            $stmt->execute([$meter === 8 ? 3 : 1, $meter, $competence, $previous, $reading, $consumption, $entry]);
        }

        $report = $this->service->build($this->user(), '1', '2026-11');
        self::assertSame(['meters' => 6, 'read' => 5, 'billed' => 1, 'short_history' => 4, 'missing' => 1], $report['totals']);
        self::assertSame(['missing' => 1, 'sequence_break' => 1, 'zero' => 1, 'spike' => 1, 'drop' => 0], $report['summary']);
        self::assertSame([[1, 'A-101', ['spike'], 10.0], [4, 'A-104', ['missing'], null], [5, 'A-105', ['zero'], null], [6, 'A-106', ['sequence_break'], null]],
            array_map(static fn (array $i): array => [$i['meter_id'], $i['unit_label'], $i['issues'], $i['average']], $report['items']));
        self::assertStringNotContainsString('M8', (string)json_encode($report), 'Sem relógio de outro condomínio.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, '2026-11', null], ['x', '2026-11', null], [1, '2026-13', null], [1, null, null], [1, '11/2026', null], [1, '2026-11', '1'], [1, '2026-11', '9'],
            [1, '2026-11', 'abc']] as [$condominium, $competence, $factor]) {
            try {
                $this->service->build($this->user(), $condominium, $competence, $factor);
                self::fail('Entrada inválida passou: ' . json_encode([$condominium, $competence, $factor]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3, '2026-11');
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $body = explode('final class', (string)file_get_contents($root . '/source/Services/Erp/MeterReadingReview.php'))[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'NotificationOutbox', 'erp_residents', 'erp_people'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem morador: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/MeterReadingReview.php');
        foreach (["Access::can('erp.meter_readings.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/meter-reading-review", "MeterReadingReview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
