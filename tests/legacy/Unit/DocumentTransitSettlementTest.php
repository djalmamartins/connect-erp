<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DocumentTransitSettlement;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0216: baixa em lote de malotes e avisos de recebimento. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2;
 * o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, a recusa do lote antes de gravar e o
 * contrato de reuso; a regravação pelo formulário é conferida no MariaDB (Integration\DocumentTransitSettlementTest).
 */
final class DocumentTransitSettlementTest extends TestCase
{
    private PDO $pdo;
    private DocumentTransitSettlement $service;

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
            'CREATE TABLE erp_pouches (id INTEGER PRIMARY KEY, condominium_id INTEGER, sent_on TEXT, courier TEXT, documents_count INTEGER, contents TEXT,
                received_on TEXT, checked_on TEXT, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_delivery_notices (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, recipient TEXT, tracking_code TEXT, subject TEXT,
                sent_on TEXT, delivered_on TEXT, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_pouches (id, condominium_id, sent_on, courier, documents_count, contents, received_on, checked_on, status) VALUES
            (1, 1, '2026-11-01', 'Motoboy', 2, 'Notas', NULL, NULL, 'active'),
            (2, 1, '2026-10-01', 'Motoboy', 1, 'Ata', '2026-10-02', '2026-10-03', 'checked'),
            (3, 3, '2026-11-01', 'Outro', 1, 'Outro', NULL, NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_delivery_notices (id, condominium_id, recipient, tracking_code, sent_on, delivered_on, status) VALUES
            (1, 1, 'Morador', 'AR1BR', '2026-11-01', '2026-11-03', 'delivered')");
        $this->service = new DocumentTransitSettlement($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSettlementPureRule(): void
    {
        $on = '2026-11-10';
        self::assertSame(['status' => 'received', 'received_on' => $on], DocumentTransitSettlement::settlement('pouch', 'active', $on));
        self::assertSame(['status' => 'checked', 'received_on' => $on, 'checked_on' => $on], DocumentTransitSettlement::settlement('pouch', 'active', $on, true));
        self::assertSame(['status' => 'checked', 'checked_on' => $on], DocumentTransitSettlement::settlement('pouch', 'received', $on));
        self::assertNull(DocumentTransitSettlement::settlement('pouch', 'checked', $on, true));
        self::assertSame(['status' => 'delivered', 'delivered_on' => $on], DocumentTransitSettlement::settlement('notice', 'active', $on));
        self::assertSame(['status' => 'returned'], DocumentTransitSettlement::settlement('notice', 'active', $on, false, 'returned'));
        self::assertNull(DocumentTransitSettlement::settlement('notice', 'delivered', $on));
        self::assertNull(DocumentTransitSettlement::settlement('notice', 'returned', $on));
        self::assertNull(DocumentTransitSettlement::settlement('other', 'active', $on));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'on' => '2026-11-10', 'pouch_ids' => [1]];
        foreach ([['condominium_id' => 'x'] + $ok, ['on' => '10/11/2026'] + $ok, ['on' => '2026-02-30'] + $ok, ['on' => date('Y-m-d', strtotime('+2 days'))] + $ok,
            ['outcome' => 'lost'] + $ok, ['pouch_ids' => 'x'] + $ok, ['pouch_ids' => ['a']] + $ok, ['pouch_ids' => [0]] + $ok, ['pouch_ids' => []] + $ok,
            ['pouch_ids' => range(1, 201)] + $ok] as $input) {
            try {
                $this->service->settle($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->settle($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testBatchRefusedBeforeWriting(): void
    {
        try {
            $this->service->settle($this->user(), ['condominium_id' => 1, 'on' => '2026-11-10', 'pouch_ids' => [1, 2, 3, 99], 'notice_ids' => [1]]);
            self::fail('Lote com documento resolvido ou de outro condomínio passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame(['pouch:2' => 'Já tem baixa.', 'pouch:3' => 'Não encontrado neste condomínio.', 'pouch:99' => 'Não encontrado neste condomínio.',
                'notice:1' => 'Já tem baixa.'], $exception->errors);
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_pouches WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/DocumentTransitSettlement.php');
        foreach (['$registry->save($user, self::KINDS[$kind][\'slug\'], array_merge($data, $change), $id)', ' FOR UPDATE', "Audit::record('transit_settle'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', "['courier']", "['recipient']", "['tracking_code']", "['contents']", "['subject']"] as $needle) {
            self::assertStringNotContainsString($needle, $service, 'Só pelo formulário e sem dado pessoal na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/DocumentTransitSettlement.php');
        foreach (["Access::can('erp.access', \$user)", "'erp.pouches.manage'", "'erp.delivery_notices.manage'", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/document-transit/settle", "DocumentTransitSettlement:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
