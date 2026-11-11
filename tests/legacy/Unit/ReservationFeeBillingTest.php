<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\ReservationFeeBilling;

/**
 * ADR 0212: cobrança das taxas de reserva em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra
 * pura, a validação, o escopo e o contrato de reuso; a cobrança pelo EntryShortcuts::createCharge é conferida no MariaDB
 * (Integration\ReservationFeeBillingTest).
 */
final class ReservationFeeBillingTest extends TestCase
{
    private ReservationFeeBilling $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)'] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new ReservationFeeBilling($pdo, new CondominiumScope($pdo));
    }

    public function testDescriptionPureRule(): void
    {
        self::assertSame('Taxa de reserva: Salão de festas em 03/10/2026', ReservationFeeBilling::description('Salão de festas', '2026-10-03'));
        self::assertSame('Taxa de reserva: Churrasqueira em 31/12/2026', ReservationFeeBilling::description('  Churrasqueira ', '2026-12-31 00:00:00'));
        $long = ReservationFeeBilling::description(str_repeat('Área muito comprida ', 10), '2026-10-03');
        self::assertSame(120, mb_strlen($long));
        self::assertStringEndsWith(' em 03/10/2026', $long, 'A data nunca é cortada.');
    }

    public function testValidationScopeAndReuseContract(): void
    {
        $due = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $base = ['condominium_id' => 1, 'from' => '2026-10-01', 'to' => '2026-10-31', 'due_on' => $due, 'chart_account_id' => 5];
        foreach ([['condominium_id' => null], ['condominium_id' => 'x'], ['from' => null], ['from' => '2026-02-30'], ['to' => '2026-09-30'],
            ['from' => '2020-01-01', 'to' => '2026-10-31'], ['due_on' => null], ['due_on' => '2026-02-30'], ['due_on' => '2020-01-01'], ['due_on' => '10/11/2030']] as $change) {
            try {
                $this->service->bill($this->user(), array_merge($base, $change));
                self::fail('Entrada inválida passou: ' . json_encode($change));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->bill($this->user(), ['condominium_id' => 3] + $base);
            self::fail('Fora do escopo passou.');
        } catch (RegistryNotFound) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ReservationFeeBilling.php');
        foreach (['$shortcuts->createCharge($user', "'reservation_id' => (int)\$row['id']", 'CollectionReports::period(', "x.kind = 'extra' AND x.status = 'active'",
            ' FOR UPDATE', "Audit::record('bill_fee'", '$this->pdo->rollBack()', "'competence' => substr(\$on, 0, 7)"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['INSERT ', 'UPDATE ', 'NotificationOutbox', 'resident_name'] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Sem lançamento paralelo nem dado de morador: ' . $needle);
        }
        $shortcuts = (string)file_get_contents($root . '/source/Services/Erp/EntryShortcuts.php');
        foreach (['$own = !$this->pdo->inTransaction();', "\$reservation = \$this->int(\$input['reservation_id'] ?? null);", 'A cobrança de uma reserva é de uma unidade só.',
            'A reserva da multa precisa ser desta unidade.'] as $needle) {
            self::assertStringContainsString($needle, $shortcuts, $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ReservationFeeBilling.php');
        foreach (["'erp.reservations.view'", "'erp.invoices.manage'", "'erp.invoices.view'", 'catch (RegistryNotFound)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/reservations/bill-fees", "ReservationFeeBilling:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
