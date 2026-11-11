<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ObligationCalendar;

/**
 * ADR 0218: calendário de vencimentos, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para
 * as regras puras, seguros, contratos e mandatos, a validação, o escopo e as fontes ocultas; o checklist de chegada
 * (OnboardingChecklist, SQL do MariaDB) é conferido em Integration\ObligationCalendarTest.
 */
final class ObligationCalendarTest extends TestCase
{
    private PDO $pdo;
    private ObligationCalendar $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_insurances (id INTEGER PRIMARY KEY, condominium_id INTEGER, insurer TEXT, policy_number TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_contracts (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, object TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_board_members (id INTEGER PRIMARY KEY, condominium_id INTEGER, role TEXT, name TEXT, document TEXT, email TEXT, phone TEXT,
                term_ends_on TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_insurances (id, condominium_id, insurer, policy_number, ends_on, status) VALUES
            (1, 1, 'Seguradora A', 'AP-1', '2026-11-20', 'active'), (2, 1, 'Seguradora B', 'AP-2', '2026-10-01', 'active'),
            (3, 1, 'Seguradora C', 'AP-3', '2026-12-01', 'inactive'), (4, 3, 'Outra', 'AP-4', '2026-11-20', 'active')");
        $this->pdo->exec("INSERT INTO erp_contracts (id, condominium_id, supplier_id, object, ends_on, status) VALUES
            (1, 1, 9, 'Portaria 24h', '2027-01-05', 'active'), (2, 1, 9, 'Limpeza', NULL, 'active'), (3, 1, 9, 'Jardim', '2027-06-01', 'active')");
        $this->pdo->exec("INSERT INTO erp_board_members (id, condominium_id, role, name, document, email, phone, term_ends_on, status) VALUES
            (1, 1, 'sindico', 'Fulano de Tal', '12345678909', 'fulano@example.com', '1699999', '2026-12-20', 'active')");
        $this->service = new ObligationCalendar($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testPureRules(): void
    {
        self::assertSame(-41, ObligationCalendar::daysUntil('2026-10-01', '2026-11-11'));
        self::assertSame(0, ObligationCalendar::daysUntil('2026-11-11', '2026-11-11'));
        self::assertSame(['expired', '30', '30', '60', '60', '90', null], array_map(static fn (string $d): ?string => ObligationCalendar::bucket($d, '2026-11-11'),
            ['2026-11-10', '2026-11-11', '2026-12-11', '2026-12-12', '2027-01-10', '2027-02-09', '2027-02-10']));
    }

    public function testBuildBySourceWithoutPersonalData(): void
    {
        $report = $this->service->build($this->user(), '1', ['insurances', 'contracts', 'board'], '2026-11-11');
        self::assertSame(['expired' => 1, '30' => 1, '60' => 2, '90' => 0], $report['summary']);
        self::assertSame([['insurances', 2, 'expired', -41], ['insurances', 1, '30', 9], ['board', 1, '60', 39], ['contracts', 1, '60', 55]],
            array_map(static fn (array $i): array => [$i['source'], $i['id'], $i['bucket'], $i['days']], $report['items']));
        self::assertSame('Mandato: Síndico', $report['items'][2]['label']);
        self::assertSame(['checklist'], $report['hidden_sources']);
        $json = (string)json_encode($report);
        foreach (['Fulano', '12345678909', 'fulano@', '1699999', 'Outra'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal nem de outro condomínio: ' . $needle);
        }

        $thirty = $this->service->build($this->user(), 1, ['insurances', 'contracts', 'board'], '2026-11-11', '30');
        self::assertSame([2, 1], [count($thirty['items']), $thirty['summary']['30']], 'Vencidos sempre entram; futuros até a janela.');
        $only = $this->service->build($this->user(), 1, ['contracts', 'nope'], '2026-11-11');
        self::assertSame([['contracts'], ['insurances', 'board', 'checklist']], [$only['sources'], $only['hidden_sources']]);
        self::assertSame(['contracts'], array_values(array_unique(array_column($only['items'], 'source'))));
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, '11/11/2026', null], [1, '2026-13-01', null], [1, null, '45'], [1, null, 'x']] as [$c, $asOf, $days]) {
            try {
                $this->service->build($this->user(), $c, ['contracts'], $asOf, $days);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $asOf, $days]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3, ['contracts']);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ObligationCalendar.php');
        self::assertStringContainsString('(new OnboardingChecklist($this->pdo, $date))->items(', $service);
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'NotificationOutbox', 'mail_queue', "['responsible']", "['notes']", ' name,', 'email', 'phone', ' document,'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ObligationCalendar.php');
        foreach (["'erp.insurances.view'", "'erp.contracts.view'", "'erp.board.view'", "'erp.condominiums.view'", "Access::can('erp.access', \$user) || \$sources === []",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/obligation-calendar", "ObligationCalendar:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
