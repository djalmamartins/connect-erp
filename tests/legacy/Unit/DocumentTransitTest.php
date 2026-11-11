<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\DocumentTransit;

/**
 * ADR 0215: documentos em trânsito (malotes e avisos de recebimento), somente leitura. Unit com SQLite (Pdo\Sqlite
 * quando existe; PDO no PHP 8.2) para a regra pura, a validação, o escopo e a montagem; os cadastros reais pelo
 * formulário são conferidos no MariaDB (Integration\DocumentTransitTest).
 */
final class DocumentTransitTest extends TestCase
{
    private PDO $pdo;
    private DocumentTransit $service;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_pouches (id INTEGER PRIMARY KEY, condominium_id INTEGER, sent_on TEXT, courier TEXT, documents_count INTEGER, contents TEXT,
                received_on TEXT, checked_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_delivery_notices (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, recipient TEXT, tracking_code TEXT, subject TEXT,
                sent_on TEXT, delivered_on TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo = $pdo;
        $this->service = new DocumentTransit($pdo, new CondominiumScope($pdo));
    }

    public function testStagePureRule(): void
    {
        self::assertSame(['stage' => 'awaiting_receipt', 'since' => '2026-11-01'], DocumentTransit::stage('pouch', ['status' => 'active', 'sent_on' => '2026-11-01', 'received_on' => null]));
        self::assertSame(['stage' => 'awaiting_check', 'since' => '2026-11-03'], DocumentTransit::stage('pouch', ['status' => 'received', 'sent_on' => '2026-11-01',
            'received_on' => '2026-11-03', 'checked_on' => null]));
        self::assertNull(DocumentTransit::stage('pouch', ['status' => 'checked', 'sent_on' => '2026-11-01', 'received_on' => '2026-11-03', 'checked_on' => '2026-11-04']));
        self::assertSame(['stage' => 'in_transit', 'since' => '2026-11-02'], DocumentTransit::stage('notice', ['status' => 'active', 'sent_on' => '2026-11-02', 'delivered_on' => null]));
        self::assertNull(DocumentTransit::stage('notice', ['status' => 'delivered', 'sent_on' => '2026-11-02', 'delivered_on' => '2026-11-05']));
        self::assertNull(DocumentTransit::stage('notice', ['status' => 'returned', 'sent_on' => '2026-11-02']));
        self::assertNull(DocumentTransit::stage('other', ['status' => 'active', 'sent_on' => '2026-11-02']));
    }

    public function testBuildWithoutPersonalData(): void
    {
        $this->pdo->exec("INSERT INTO erp_pouches (id, condominium_id, sent_on, courier, documents_count, contents, received_on, checked_on, status) VALUES
            (1, 1, '2026-10-20', 'Motoboy Fulano', 3, 'Notas do Sr. Beltrano', NULL, NULL, 'active'),
            (2, 1, '2026-11-01', 'Motoboy Fulano', 2, 'Boletos', '2026-11-08', NULL, 'received'),
            (3, 1, '2026-10-01', 'Motoboy Fulano', 1, 'Ata', '2026-10-02', '2026-10-03', 'checked'),
            (4, 3, '2026-10-01', 'Outro', 1, 'Outro', NULL, NULL, 'active'),
            (5, 1, '2026-11-20', 'Futuro', 1, 'Depois', NULL, NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_delivery_notices (id, condominium_id, unit_id, recipient, tracking_code, subject, sent_on, delivered_on, status) VALUES
            (1, 1, 11, 'Ciclano da Silva', 'AR123BR', 'Multa', '2026-10-30', NULL, 'active'),
            (2, 1, 12, 'Ciclano da Silva', 'AR124BR', 'Multa', '2026-10-01', '2026-10-05', 'delivered')");
        $report = $this->service->build($this->user(), '1', '2026-11-10');
        self::assertSame([7, 2], [$report['days'], $report['overdue']]);
        self::assertSame(['awaiting_receipt' => ['open' => 1, 'overdue' => 1], 'awaiting_check' => ['open' => 1, 'overdue' => 0],
            'in_transit' => ['open' => 1, 'overdue' => 1]], $report['summary']);
        self::assertSame([['pouch', 1, 21, 3, null], ['notice', 1, 11, null, 11], ['pouch', 2, 2, 2, null]], array_map(static fn (array $i): array => [$i['kind'],
            $i['id'], $i['days'], $i['documents_count'], $i['unit_id']], $report['items']));
        self::assertSame(0, $this->service->build($this->user(), 1, '2026-11-10', '30')['overdue']);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Beltrano', 'Ciclano', 'AR123BR', 'Multa', 'courier', 'recipient', 'tracking', 'contents', 'subject'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem portador, conteúdo, destinatário nem rastreio: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [0, null, null], [1, '10/11/2026', null], [1, '2026-02-30', null], [1, null, '0'], [1, null, '91'],
            [1, null, 'x']] as [$condominium, $asOf, $days]) {
            try {
                $this->service->build($this->user(), $condominium, $asOf, $days);
                self::fail('Entrada inválida passou: ' . json_encode([$condominium, $asOf, $days]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/DocumentTransit.php');
        foreach (['Agreements::daysLate(', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'NotificationOutbox', 'courier,', 'recipient', 'tracking_code', 'contents,', 'subject'] as $needle) {
            self::assertStringNotContainsString($needle, explode('final class', $service)[1], 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/DocumentTransit.php');
        foreach (["Access::can('erp.pouches.view', \$user)", "Access::can('erp.delivery_notices.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/document-transit", "DocumentTransit:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
