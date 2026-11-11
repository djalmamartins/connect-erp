<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AgreementWatch;

/**
 * ADR 0207: acompanhamento dos acordos. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura, a
 * validação, o escopo e o contrato; a leitura dos acordos e da regra do condomínio é conferida no MariaDB
 * (Integration\AgreementWatchTest).
 */
final class AgreementWatchTest extends TestCase
{
    private const RULE = ['breach_days_late' => 30, 'breach_open_installments' => 2];

    private AgreementWatch $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT)',
            "CREATE TABLE erp_agreements (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, status TEXT, installments INTEGER, total_cents INTEGER, created_at TEXT)"] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        // Só acordos já resolvidos (quebrado, cancelado): nada a acompanhar, e a regra do condomínio nem é lida.
        $pdo->exec("INSERT INTO erp_units (id, condominium_id, label) VALUES (10, 1, '101')");
        $pdo->exec("INSERT INTO erp_agreements (id, condominium_id, unit_id, status, installments, total_cents, created_at) VALUES
            (1, 1, 10, 'breached', 3, 30000, '2026-01-01 10:00:00'), (2, 1, 10, 'cancelled', 3, 30000, '2026-01-01 10:00:00')");
        $this->service = new AgreementWatch($pdo, new CondominiumScope($pdo));
    }

    public function testClassifyPureRule(): void
    {
        $p = static fn (int $n, string $due, ?string $paid = null, string $status = 'active', int $cents = 10000): array =>
            ['number' => $n, 'due_on' => $due, 'paid_on' => $paid, 'status' => $status, 'amount_cents' => $cents];
        $onTrack = AgreementWatch::classify([$p(1, '2026-10-10', '2026-10-09'), $p(2, '2026-11-10'), $p(3, '2026-12-10')], self::RULE, '2026-11-10');
        self::assertSame(['on_track', 0, 0, 20000, '2026-11-10', null, null], [$onTrack['state'], $onTrack['overdue_installments'], $onTrack['overdue_cents'],
            $onTrack['open_cents'], $onTrack['next_due_on'], $onTrack['days_to_breach'], $onTrack['installments_to_breach']], 'Vence hoje não está vencida.');

        $late = AgreementWatch::classify([$p(1, '2026-10-10', '2026-10-09'), $p(2, '2026-11-10'), $p(3, '2026-12-10')], self::RULE, '2026-11-15');
        self::assertSame(['late', 1, 10000, 5, 26, 1, '2026-12-10', []], [$late['state'], $late['overdue_installments'], $late['overdue_cents'], $late['max_days_late'],
            $late['days_to_breach'], $late['installments_to_breach'], $late['next_due_on'], $late['reasons']], 'Quebra no 31º dia de atraso ou com 2 vencidas.');

        $byDays = AgreementWatch::classify([$p(1, '2026-10-10'), $p(2, '2026-12-10')], self::RULE, '2026-11-10');
        self::assertSame(['breach_due', 31, 1], [$byDays['state'], $byDays['max_days_late'], count($byDays['reasons'])]);
        $byCount = AgreementWatch::classify([$p(1, '2026-10-20'), $p(2, '2026-11-01')], self::RULE, '2026-11-10');
        self::assertSame(['breach_due', 2, 21, null, null], [$byCount['state'], $byCount['overdue_installments'], $byCount['max_days_late'],
            $byCount['days_to_breach'], $byCount['installments_to_breach']]);
        $onlyDays = AgreementWatch::classify([$p(1, '2026-10-20'), $p(2, '2026-11-01')], ['breach_days_late' => 30, 'breach_open_installments' => 0], '2026-11-10');
        self::assertSame(['late', 10, null], [$onlyDays['state'], $onlyDays['days_to_breach'], $onlyDays['installments_to_breach']], 'Regra sem limite de parcelas.');
        $cancelled = AgreementWatch::classify([$p(1, '2026-01-10', null, 'inactive')], self::RULE, '2026-11-10');
        self::assertSame(['on_track', 0, null], [$cancelled['state'], $cancelled['open_cents'], $cancelled['next_due_on']], 'Parcela cancelada não conta.');
    }

    public function testValidationScopeAndContract(): void
    {
        $r = $this->service->build($this->user(), 1, '2026-11-10');
        self::assertSame([1, [], false, 0], [$r['condominium_id'], $r['items'], $r['truncated'], array_sum(array_column($r['summary'], 'agreements'))],
            'Quebrado e cancelado ficam de fora.');
        self::assertSame(['breach_due', 'late', 'on_track'], array_keys($r['summary']));
        self::assertNull($this->service->build($this->user(), null, '2026-11-10')['condominium_id'], 'Sem condomínio: todos do escopo.');
        foreach ([[1, '2026-02-30'], [1, '10/11/2026'], [1, ['2026-11-10']], ['x', '2026-11-10'], [0, '2026-11-10'], [['1'], '2026-11-10']] as [$c, $d]) {
            try {
                $this->service->build($this->user(), $c, $d);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $d]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3, '2026-11-10');
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/AgreementWatch.php');
        foreach (['LateCharges::breach(', "a.status = 'active'", '$rules->rule($condo)'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['NotificationOutbox', 'Audit::record', 'UPDATE ', 'INSERT ', 'r.name', 'r.email'] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Somente leitura, sem dado de morador: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/AgreementWatch.php');
        foreach (["Access::can('erp.agreements.view', \$user)", "Access::can('erp.entries.view', \$user)", 'catch (CondominiumAccessDenied)', 'no-store'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/agreement-watch", "AgreementWatch:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
