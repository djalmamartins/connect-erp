<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContactDeduplication;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0257: inativação em lote dos contatos úteis duplicados. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2;
 * o PDO de teste tira o FOR UPDATE) para a regra pura das cópias e do telefone, a validação, o escopo, as recusas antes do
 * formulário e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB
 * (Integration\ContactDeduplicationTest).
 */
final class ContactDeduplicationTest extends TestCase
{
    private PDO $pdo;
    private ContactDeduplication $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_useful_contacts (id INTEGER PRIMARY KEY, condominium_id INTEGER, service TEXT, name TEXT, phone TEXT, email TEXT, notes TEXT, status TEXT,
                deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_useful_contacts (id, condominium_id, service, name, phone, email, notes, status, deleted_at) VALUES
            (1, 1, 'emergencia', 'Bombeiros copia', '193', NULL, NULL, 'active', NULL), (2, 1, 'encanador', 'Encanador Fulano', '16990000001', NULL, NULL, 'active', NULL),
            (3, 1, 'encanador', 'Encanador de novo', '5516990000001', NULL, NULL, 'active', NULL), (4, 1, 'eletricista', 'Eletrica', '16994007100', NULL, NULL, 'active', NULL),
            (5, 1, 'gas', 'Gas', '16990000002', NULL, NULL, 'inactive', NULL), (6, 3, 'emergencia', 'De fora', '193', NULL, NULL, 'active', NULL),
            (7, 1, 'emergencia', 'Excluido', '193', NULL, NULL, 'active', '2026-01-01 00:00:00'),
            (10, NULL, 'emergencia', 'Bombeiros', '193', NULL, NULL, 'active', NULL), (11, NULL, 'eletricista', 'Eletrica geral', '16994007100', NULL, NULL, 'inactive', NULL)");
        $this->service = new ContactDeduplication($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testDuplicatesPureRule(): void
    {
        self::assertSame(['16990000001', '16990000001', '193', ''], [ContactDeduplication::phoneKey('5516990000001'), ContactDeduplication::phoneKey('(16) 99000-0001'),
            ContactDeduplication::phoneKey('193'), ContactDeduplication::phoneKey(null)]);
        $c = static fn (int $id, bool $shared, string $service, ?string $phone): array => ['id' => $id, 'shared' => $shared, 'service' => $service, 'phone' => $phone];
        self::assertSame([1 => 10, 3 => 2, 8 => 10], ContactDeduplication::duplicates([$c(1, false, 'emergencia', '193'), $c(10, true, 'emergencia', '193'),
            $c(12, true, 'emergencia', '193'), $c(8, false, 'emergencia', '193'), $c(3, false, 'encanador', '5516990000001'), $c(2, false, 'encanador', '16990000001'),
            $c(4, false, 'eletricista', '16990000001'), $c(5, false, 'gas', null), $c(6, false, 'gas', null)]),
            'O compartilhado mais antigo fica; entre próprios, o menor id; outro serviço não é cópia; sem telefone não compara; compartilhado nunca sai.');
        self::assertSame([], ContactDeduplication::duplicates([$c(10, true, 'emergencia', '193'), $c(12, true, 'emergencia', '193')]));
        self::assertNull(ContactDeduplication::problem(['status' => 'active'], 10));
        self::assertStringContainsString('não encontrado', (string)ContactDeduplication::problem(null, 10));
        self::assertStringContainsString('já está inativo', (string)ContactDeduplication::problem(['status' => 'inactive'], 10));
        self::assertStringContainsString('não é cópia', (string)ContactDeduplication::problem(['status' => 'active'], null));
    }

    public function testValidationAndScope(): void
    {
        foreach ([['condominium_id' => 0], ['condominium_id' => 'x'], ['condominium_id' => 1, 'contact_ids' => []], ['condominium_id' => 1, 'contact_ids' => ['x']],
            ['condominium_id' => 1, 'contact_ids' => 'abc'], ['condominium_id' => 1, 'contact_ids' => range(1, 501)]] as $input) {
            try {
                $this->service->deduplicate($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->deduplicate($this->user(), ['condominium_id' => 3]);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[4, 'não é cópia'], [2, 'não é cópia'], [5, 'já está inativo'], [6, 'não encontrado'], [7, 'não encontrado'], [10, 'compartilhados'],
            [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->deduplicate($this->user(), ['condominium_id' => 1, 'contact_ids' => [1, $id]]);
                self::fail("Contato {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_contact']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Bombeiros', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_useful_contacts WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testNothingToDoWhenThereAreNoCopies(): void
    {
        $this->pdo->exec("UPDATE erp_useful_contacts SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 3)");
        $result = $this->service->deduplicate($this->user(), ['condominium_id' => '1', 'dry_run' => true]);
        self::assertSame([0, 0, 0, []], [$result['count'], $result['changed'], $result['kept_shared'], $result['contacts']]);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ContactDeduplication.php');
        foreach (["\$registry->save(\$user, 'contacts', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('deduplicate'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('contacts')", 'condominium_id IS NULL AND deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'name' =>", "['name']", "'phone' => \$c", "['email']"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem dado do contato na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ContactDeduplication.php');
        foreach (["Access::can('erp.contacts.view', \$user)", "Access::can('erp.contacts.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/useful-contacts/deactivate-duplicates", "ContactDeduplication:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
