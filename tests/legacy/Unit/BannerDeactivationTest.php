<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BannerDeactivation;
use Source\Services\Erp\BannerOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0287: banners encerrados ainda ativos inativados em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE e ganha NOW()) para a regra pura, a validação, o escopo, as recusas (uma por
 * caso, tudo desfeito), a prévia, os de todos os condomínios só para quem acessa todos (os outros contados à parte), a
 * inativação gravada só no status, sem apagar, o relatório da 0286 depois e o contrato. Os alcances vêm de
 * BannerOverview::SCOPES. O MariaDB confere com banners de verdade (Integration\BannerDeactivationTest).
 */
final class BannerDeactivationTest extends TestCase
{
    private PDO $pdo;
    private BannerDeactivation $service;

    protected function setUp(): void
    {
        $now = static fn (): string => '2026-11-14 12:00:00';
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->createFunction('NOW', $now, 0);
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_banners (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, title TEXT, message TEXT, starts_on TEXT, ends_on TEXT, position INTEGER NULL,
                status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        // O usuário 8 acessa todos os condomínios (a permissão do CondominiumScope, por papel).
        $this->pdo->exec("INSERT INTO access_roles (id, slug) VALUES (1, 'gestor')");
        $this->pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (8, 1)');
        $this->pdo->prepare('INSERT INTO access_permissions (id, slug) VALUES (1, ?)')->execute([CondominiumScope::ALL_PERMISSION]);
        $this->pdo->exec('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (1, 1)');
        $insert = $this->pdo->prepare("INSERT INTO erp_banners (id, condominium_id, title, message, starts_on, ends_on, position, status, created_by, updated_by, deleted_at)
            VALUES (?, ?, 'Recado do Fulano', 'Ligue para Fulano de Tal', ?, ?, NULL, ?, 3, 3, ?)");
        foreach ([[1, 1, '2026-10-01', '2026-11-13', 'active', null], [2, 1, '2026-11-01', '2026-11-30', 'active', null],
            [3, 1, '2026-10-01', '2026-10-31', 'inactive', null], [4, 1, '2026-11-20', '2026-12-10', 'active', null],
            [5, null, '2026-01-01', '2026-02-01', 'active', null], [6, null, '2026-11-01', '2026-11-30', 'active', null],
            [7, 3, '2026-10-01', '2026-10-31', 'active', null], [8, 1, '2026-09-01', '2026-09-30', 'active', null],
            [9, 1, '2026-10-01', '2026-10-31', 'active', '2026-11-01 10:00:00']] as $row) {
            $insert->execute($row);
        }
        $this->service = new BannerDeactivation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemRule(): void
    {
        $ended = ['global' => false, 'status' => 'active', 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-13'];
        self::assertNull(BannerDeactivation::problem($ended, '2026-11-14', false));
        self::assertNotNull(BannerDeactivation::problem(null, '2026-11-14', true));
        self::assertNotNull(BannerDeactivation::problem(['status' => 'inactive'] + $ended, '2026-11-14', true));
        self::assertNotNull(BannerDeactivation::problem(['ends_on' => '2026-11-14'] + $ended, '2026-11-14', true), 'Acaba hoje: ainda no ar.');
        self::assertNotNull(BannerDeactivation::problem(['starts_on' => '2026-11-15', 'ends_on' => '2026-11-20'] + $ended, '2026-11-14', true));
        self::assertNotNull(BannerDeactivation::problem(['global' => true] + $ended, '2026-11-14', false));
        self::assertNull(BannerDeactivation::problem(['global' => true] + $ended, '2026-11-14', true));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Limpeza dos banners'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['banner_ids' => []] + $ok, ['banner_ids' => 'x'] + $ok, ['banner_ids' => ['a']] + $ok, ['banner_ids' => [0]] + $ok,
            ['banner_ids' => range(1, BannerDeactivation::MAX_BANNERS + 1)] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(7), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['active', 'active'], [$this->bannerStatus(1), $this->bannerStatus(8)], 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->deactivate($this->user(7), ['condominium_id' => 3] + $ok);
    }

    public function testDryRunThenDeactivateWithoutGlobalAccess(): void
    {
        $input = ['condominium_id' => '1', 'reason' => 'Banners esquecidos', 'dry_run' => true];
        $preview = $this->service->deactivate($this->user(7), $input);
        self::assertSame([[1, 8], 1, 0, true, '2026-11-14'], [array_column($preview['banners'], 'id'), $preview['skipped_global'], $preview['changed'], $preview['dry_run'],
            $preview['on']], 'O de todos os condomínios fica de fora e é contado à parte.');
        self::assertSame(['active', 'active', 'active'], [$this->bannerStatus(1), $this->bannerStatus(8), $this->bannerStatus(5)], 'A prévia desfaz.');

        $done = $this->service->deactivate($this->user(7), ['dry_run' => false] + $input);
        self::assertSame([[1, 8], 2, 2, 1], [array_column($done['banners'], 'id'), $done['count'], $done['changed'], $done['skipped_global']]);
        self::assertSame(array_keys(BannerOverview::SCOPES), array_column($done['by_scope'], 'scope'));
        self::assertSame($done['count'], array_sum(array_column($done['by_scope'], 'banners')), 'O total é a soma dos alcances.');
        self::assertSame(['inactive', 'inactive', 'active', 'active', 'active', 'inactive'], [$this->bannerStatus(1), $this->bannerStatus(8), $this->bannerStatus(5), $this->bannerStatus(7),
            $this->bannerStatus(2), $this->bannerStatus(3)], 'Só os encerrados deste condomínio.');
        $row = $this->pdo->query('SELECT title, message, starts_on, ends_on, updated_by, deleted_at FROM erp_banners WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['Recado do Fulano', 'Ligue para Fulano de Tal', '2026-10-01', '2026-11-13', 7, null], [$row['title'], $row['message'], $row['starts_on'],
            $row['ends_on'], (int)$row['updated_by'], $row['deleted_at']], 'Só o status e quem alterou; nada é apagado.');
        self::assertSame('active', $this->bannerStatus(9), 'O apagado não entra.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Recado', 'Ligue', 'title', 'message'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $report = (new BannerOverview($this->pdo, new CondominiumScope($this->pdo)))->build($this->user(7), 1, '2026-11-14');
        self::assertSame([5], $report['ended_active_ids'], 'No relatório da 0286, sobra só o de todos os condomínios.');
        $again = $this->service->deactivate($this->user(7), $input);
        self::assertSame([0, 1], [$again['count'], $again['skipped_global']]);
    }

    public function testGlobalAccessIncludesTheBannersForAll(): void
    {
        $done = $this->service->deactivate($this->user(8), ['condominium_id' => 1, 'reason' => 'Banners esquecidos', 'banner_ids' => ['5', 1]]);
        self::assertSame([[1, 5], 0], [array_column($done['banners'], 'id'), $done['skipped_global']]);
        self::assertSame([['condominium', 1], ['global', 1]], array_map(static fn (array $r): array => [$r['scope'], $r['banners']], $done['by_scope']));
        self::assertSame(['inactive', 'inactive', 'active'], [$this->bannerStatus(1), $this->bannerStatus(5), $this->bannerStatus(8)], 'Com ids, só os informados.');
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach ([5 => 7, 2 => 7, 3 => 7, 4 => 7, 7 => 8, 9 => 7, 999 => 7] as $bad => $user) {
            try {
                $this->service->deactivate($this->user($user), ['condominium_id' => 1, 'reason' => 'Banners esquecidos', 'banner_ids' => [1, $bad]]);
                self::fail("O banner {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_banner']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertSame('active', $this->bannerStatus(1), 'Tudo ou nada.');
        }
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/BannerDeactivation.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('banners')", "->canWrite(\$user, 'banners'", 'BannerOverview::state(', 'FOR UPDATE',
            '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', "Audit::record('deactivate'", "AND status = 'active'", 'rowCount() !== 1'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'title', 'message', 'created_by'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar e sem título, texto ou quem cadastrou: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/BannerDeactivation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can(\$permission . '.view', \$user)", "Access::can(\$permission . '.manage', \$user)",
            'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/banners/deactivate-ended", "BannerDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/banners"'), strpos($index, $route), 'Logo depois do relatório.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/banners:\n"), strpos($openapi, "  /banners/deactivate-ended:\n"));
    }

    private function bannerStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_banners WHERE id = {$id}")->fetchColumn();
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }
}
