<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OccurrenceTicketSync;

/**
 * ADR 0213: ocorrências com o chamado do Help Desk encerrado. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2)
 * para a regra pura, a validação, o escopo e o contrato de reuso; a leitura do chamado e a regravação são conferidas no
 * MariaDB (Integration\OccurrenceTicketSyncTest).
 */
final class OccurrenceTicketSyncTest extends TestCase
{
    private OccurrenceTicketSync $service;

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
        $this->service = new OccurrenceTicketSync($pdo, new CondominiumScope($pdo));
    }

    public function testSettlesPureRule(): void
    {
        foreach ([['closed', false, true], ['closed', true, true], ['resolved', false, false], ['resolved', true, true], ['open', true, false],
            ['in_progress', true, false], ['waiting_customer', true, false], ['', true, false]] as [$status, $resolved, $expected]) {
            self::assertSame($expected, OccurrenceTicketSync::settles($status, $resolved), "{$status} / {$resolved}");
        }
    }

    public function testValidationScopeAndReuseContract(): void
    {
        foreach ([[], ['condominium_id' => 'x'], ['condominium_id' => 0], ['condominium_id' => [1]]] as $input) {
            try {
                $this->service->sync($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->sync($this->user(), ['condominium_id' => 3]);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/OccurrenceTicketSync.php');
        foreach (['$this->tickets->findFor($user', "\$registry->save(\$user, 'occurrences', array_merge(\$data, ['status' => 'done'])", ' FOR UPDATE',
            "Audit::record('ticket_sync'", '$this->pdo->rollBack()'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['studio_support_tickets', "\$ticket['requester']", "\$ticket['subject']", "\$row['subject']", "\$row['description']", 'NotificationOutbox'] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Sem consulta paralela ao Help Desk nem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/OccurrenceTicketSync.php');
        foreach (["Access::can('erp.occurrences.manage', \$user)", "Access::can('erp.occurrences.view', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/occurrences/sync-tickets", "OccurrenceTicketSync:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
