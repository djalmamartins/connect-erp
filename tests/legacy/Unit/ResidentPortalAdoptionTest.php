<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ResidentPortalAdoption;
use Source\Services\Erp\ResidentPortalPermissions;

/**
 * ADR 0268: adesão ao portal do morador, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para
 * a regra pura (as sete situações, o convite mais recente, o aceite que vale mesmo com um convite revogado depois, a ordem
 * documento antes de e-mail, totais iguais à soma), a montagem sem dado pessoal (morador inativo, com fim de vínculo
 * passado, excluído e de outro condomínio de fora; funções do portal fail-closed), a validação, o escopo e o contrato;
 * os cadastros são conferidos no MariaDB (Integration\ResidentPortalAdoptionTest).
 */
final class ResidentPortalAdoptionTest extends TestCase
{
    private PDO $pdo;
    private ResidentPortalAdoption $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, relationship TEXT, name TEXT, document TEXT, email TEXT,
                ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_resident_invitations (id INTEGER PRIMARY KEY, condominium_id INTEGER, resident_id INTEGER, token_hash TEXT, status TEXT, expires_at TEXT)',
            'CREATE TABLE erp_resident_portal_permissions (condominium_id INTEGER, feature TEXT, enabled INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new ResidentPortalAdoption($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testClassifyAndSummarizePureRules(): void
    {
        $now = '2026-11-14 10:00:00';
        self::assertSame('accepted', ResidentPortalAdoption::classify(true, ['status' => 'revoked', 'expires_at' => '2026-01-01 00:00:00'], false, false, $now),
            'Quem já aceitou continua com acesso, mesmo com um convite revogado depois.');
        self::assertSame('pending', ResidentPortalAdoption::classify(false, ['status' => 'pending', 'expires_at' => '2026-11-14 10:00:01'], true, true, $now));
        self::assertSame('expired', ResidentPortalAdoption::classify(false, ['status' => 'pending', 'expires_at' => $now], true, true, $now), 'Na hora exata já venceu.');
        self::assertSame('revoked', ResidentPortalAdoption::classify(false, ['status' => 'revoked', 'expires_at' => '2027-01-01 00:00:00'], true, true, $now));
        self::assertSame('ready', ResidentPortalAdoption::classify(false, null, true, true, $now));
        self::assertSame('no_document', ResidentPortalAdoption::classify(false, null, false, false, $now), 'Documento antes do e-mail, como no convite.');
        self::assertSame('no_email', ResidentPortalAdoption::classify(false, null, true, false, $now));

        $r = static fn (string $relationship, ?int $unit, string $situation): array => ['relationship' => $relationship, 'unit_id' => $unit, 'situation' => $situation];
        $summary = ResidentPortalAdoption::summarize([$r('owner', 1, 'accepted'), $r('tenant', 1, 'ready'), $r('resident', 2, 'expired'), $r('owner', 3, 'no_email'),
            $r('financial', null, 'revoked')], ['boletos' => true, 'documents' => true, 'unknown' => true]);
        self::assertSame([1, 0, 1, 1, 1, 0, 1], array_column($summary['rows'], 'residents'));
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['residents'], array_sum($row['by_relationship']), 'Vínculos somam a linha.');
        }
        self::assertSame(['residents' => 5, 'with_access' => 1, 'to_invite' => 3], $summary['totals']);
        self::assertSame($summary['totals']['residents'], array_sum(array_column($summary['rows'], 'residents')));
        self::assertSame(['inhabited' => 3, 'with_access' => 1, 'without_access' => 2], $summary['units']);
        self::assertSame([count(ResidentPortalPermissions::FEATURES), 2], [count($summary['features']), $summary['enabled']], 'Só as funções conhecidas.');
        self::assertSame(1, $summary['rows'][4]['by_relationship']['tenant']);
        $empty = ResidentPortalAdoption::summarize([], []);
        self::assertSame([7, 0, 0, 0], [count($empty['rows']), $empty['totals']['residents'], $empty['units']['inhabited'], $empty['enabled']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $past = date('Y-m-d', strtotime('-30 days'));
        $future = date('Y-m-d H:i:s', strtotime('+5 days'));
        $gone = date('Y-m-d H:i:s', strtotime('-5 days'));
        $this->pdo->exec("INSERT INTO erp_residents (id, condominium_id, unit_id, relationship, name, document, email, ends_on, status, deleted_at) VALUES
            (1, 1, 40, 'owner', 'Fulana Dona', '52998224725', 'fulana@exemplo.test', NULL, 'active', NULL),
            (2, 1, 40, 'tenant', 'Beltrano', '111.444.777-35', 'beltrano@exemplo.test', NULL, 'active', NULL),
            (3, 1, 41, 'resident', 'Ciclano', '52998224725', 'c@exemplo.test', NULL, 'active', NULL),
            (4, 1, 42, 'owner', 'Vencido', '52998224725', 'v@exemplo.test', NULL, 'active', NULL),
            (5, 1, 43, 'owner', 'Revogado', '52998224725', 'r@exemplo.test', NULL, 'active', NULL),
            (6, 1, 44, 'owner', 'Sem documento', '123', 'sd@exemplo.test', NULL, 'active', NULL),
            (7, 1, 44, 'owner', 'Sem e-mail', '52998224725', 'nao-e-email', NULL, 'active', NULL),
            (8, 1, 45, 'owner', 'Aceitou antes', '52998224725', 'a@exemplo.test', NULL, 'active', NULL),
            (9, 1, 46, 'owner', 'Inativo', '52998224725', 'i@exemplo.test', NULL, 'inactive', NULL),
            (10, 1, 46, 'owner', 'Saiu', '52998224725', 's@exemplo.test', '{$past}', 'active', NULL),
            (11, 1, 46, 'owner', 'Excluído', '52998224725', 'e@exemplo.test', NULL, 'active', '2026-01-01 00:00:00'),
            (12, 3, 50, 'owner', 'De fora', '52998224725', 'f@exemplo.test', NULL, 'active', NULL),
            (13, 1, NULL, 'financial', 'Financeiro', '52998224725', 'fin@exemplo.test', NULL, 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_resident_invitations (id, condominium_id, resident_id, token_hash, status, expires_at) VALUES
            (1, 1, 1, 'h1', 'accepted', '{$gone}'), (2, 1, 3, 'h2', 'pending', '{$future}'), (3, 1, 4, 'h3', 'pending', '{$gone}'),
            (4, 1, 5, 'h4', 'pending', '{$gone}'), (5, 1, 5, 'h5', 'revoked', '{$future}'), (6, 1, 8, 'h6', 'accepted', '{$gone}'),
            (7, 1, 8, 'h7', 'revoked', '{$future}'), (8, 1, 9, 'h8', 'accepted', '{$gone}'), (9, 3, 12, 'h9', 'accepted', '{$gone}')");
        $this->pdo->exec("INSERT INTO erp_resident_portal_permissions (condominium_id, feature, enabled) VALUES (1, 'boletos', 1), (1, 'reservations', 0), (3, 'documents', 1)");
        $report = $this->service->build($this->user(), '1');
        self::assertSame(['accepted' => 2, 'pending' => 1, 'expired' => 1, 'revoked' => 1, 'ready' => 2, 'no_document' => 1, 'no_email' => 1],
            array_combine(array_column($report['rows'], 'situation'), array_column($report['rows'], 'residents')));
        self::assertSame(['residents' => 9, 'with_access' => 2, 'to_invite' => 4], $report['totals']);
        self::assertSame(['inhabited' => 6, 'with_access' => 2, 'without_access' => 4], $report['units']);
        self::assertSame([2, 0, 0, 0], array_values($report['rows'][0]['by_relationship']));
        self::assertSame(1, $report['rows'][4]['by_relationship']['financial']);
        self::assertSame(1, $report['enabled'], 'Só boletos ligado neste condomínio.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulana', 'Beltrano', '52998224725', '111.444', '@', 'exemplo', 'h1', 'token', '"document"', '"email"', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, '', 'x', 0, '-1', 1.5, []] as $condominium) {
            try {
                $this->service->build($this->user(), $condominium);
                self::fail('Entrada inválida passou: ' . json_encode($condominium));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/ResidentPortalAdoption.php');
        foreach (["RegistryService::definition('residents')", '$this->scope->assertAccess(', 'deleted_at IS NULL', '(ends_on IS NULL OR ends_on >= ?)',
            'InvitationRules::validDocument(', 'ResidentPortalPermissions::enabled(', 'ResidentPortalPermissions::FEATURES'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'token_hash', 'SELECT name', "'name'", "'email' =>", "'document' =>", 'NotificationOutbox', 'mail_queue'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ResidentPortalAdoption.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.access_admin.view', \$user)", "Access::can('erp.residents.view', \$user)",
            'catch (CondominiumAccessDenied)', 'catch (InvalidArgumentException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/resident-portal", "ResidentPortalAdoption:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/vehicles/deactivate-unattached"'), strpos($index, $route), 'Logo depois da inativação dos veículos.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
