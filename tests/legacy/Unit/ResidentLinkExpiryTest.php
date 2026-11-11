<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ResidentLinkExpiry;

/**
 * ADR 0241: encerramento em lote dos vínculos cujo fim já passou. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, os tipos de vínculo, a validação, o escopo, as recusas antes do
 * formulário e o contrato de reuso; a regravação pelo cadastro, com os convites e a auditoria, é conferida no MariaDB
 * (Integration\ResidentLinkExpiryTest).
 */
final class ResidentLinkExpiryTest extends TestCase
{
    private PDO $pdo;
    private ResidentLinkExpiry $service;

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
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT, document TEXT,
                email TEXT, phone TEXT, starts_on TEXT, ends_on TEXT, billing_by_email INTEGER, notices_opt_out INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (11, 1, 'Apto 101', 'active', NULL), (12, 1, 'Apto 102', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_residents (id, condominium_id, unit_id, unit_label, relationship, name, ends_on, status, deleted_at) VALUES
            (1, 1, 11, 'Apto 101', 'tenant', 'Fulano Inquilino', '2026-10-15', 'active', NULL),
            (2, 1, 11, 'Apto 101', 'owner', 'Beltrano', '2026-10-15', 'inactive', NULL),
            (3, 1, 11, 'Apto 101', 'tenant', 'Ciclano', NULL, 'active', NULL),
            (4, 1, 11, 'Apto 101', 'tenant', 'Deltrano', '2026-11-20', 'active', NULL),
            (5, 1, 12, 'Apto 102', 'tenant', 'Ermano', '2026-10-01', 'active', NULL),
            (6, 3, NULL, 'Fora', 'tenant', 'Outro', '2026-10-01', 'active', NULL),
            (7, 1, 11, 'Apto 101', 'tenant', 'Excluído', '2026-10-01', 'active', '2026-10-02 00:00:00')");
        $this->service = new ResidentLinkExpiry($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRuleAndRelationships(): void
    {
        $row = ['status' => 'active', 'ends_on' => '2026-10-31'];
        self::assertNull(ResidentLinkExpiry::problem($row, '2026-11-01'), 'Fim no dia anterior ao corte.');
        self::assertNull(ResidentLinkExpiry::problem(['ends_on' => '2026-10-31 00:00:00'] + $row, '2026-11-01'));
        self::assertStringContainsString('não encontrado', (string)ResidentLinkExpiry::problem(null, '2026-11-01'));
        self::assertStringContainsString('já está encerrado', (string)ResidentLinkExpiry::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('saída de moradores', (string)ResidentLinkExpiry::problem(['ends_on' => null] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não passou', (string)ResidentLinkExpiry::problem(['ends_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('unidade', (string)ResidentLinkExpiry::problem($row, '2026-11-01', false));
        self::assertSame(ResidentLinkExpiry::RELATIONSHIPS, ResidentLinkExpiry::relationships(null));
        self::assertSame(ResidentLinkExpiry::RELATIONSHIPS, ResidentLinkExpiry::relationships([]));
        self::assertSame(['tenant', 'resident'], ResidentLinkExpiry::relationships(['resident', 'tenant', 'tenant']));
        self::assertNull(ResidentLinkExpiry::relationships(['inquilino']));
        self::assertNull(ResidentLinkExpiry::relationships('tenant'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Contrato encerrado', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['relationships' => ['x']] + $ok, ['resident_ids' => []] + $ok, ['resident_ids' => ['x']] + $ok,
            ['resident_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->expire($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->expire($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'já está encerrado'], [3, 'saída de moradores'], [4, 'ainda não passou'], [5, 'unidade'], [6, 'não encontrado'], [7, 'não encontrado'],
            [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->expire($this->user(), ['condominium_id' => 1, 'resident_ids' => [1, $id], 'reason' => 'Contrato encerrado', 'before' => '2026-11-01']);
                self::fail("Vínculo {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_resident']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_residents WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ResidentLinkExpiry.php');
        foreach (["\$registry->save(\$user, 'residents', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('expire'",
            "UPDATE erp_resident_invitations SET status = 'revoked'", '$this->pdo->rollBack()', '$this->scope->assertAccess(',
            'AND ends_on IS NOT NULL AND ends_on < ?'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_residents', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'name' =>", "['name']", "['document']", "'ends_on' => \$before"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, mantém o fim, sem aviso e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ResidentLinkExpiry.php');
        foreach (["Access::can('erp.residents.view', \$user)", "Access::can('erp.residents.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/residents/expire-ended", "ResidentLinkExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
