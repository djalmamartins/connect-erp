<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ErpAccess;
use Source\Services\Erp\UserAccessReport;

/**
 * ADR 0282: acessos dos usuários ao ERP por condomínio, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2) para a regra pura (papel de maior nível, sem papel, acesso ao ERP pela regra de ErpAccess::effective,
 * exceções por efeito, situação da conta, ids e corte, totais iguais à soma), a montagem sem nome, e-mail nem documento
 * (outro condomínio de fora), a validação, o escopo e o contrato. Os papéis são os do teste; os efeitos vêm de
 * ErpAccess::EFFECTS, sem quantidade nem lista fixa. O MariaDB confere com o controle de acesso de verdade
 * (Integration\UserAccessReportTest).
 */
final class UserAccessReportTest extends TestCase
{
    private PDO $pdo;
    private UserAccessReport $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, document TEXT, status TEXT)',
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, level INTEGER)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec("INSERT INTO access_roles (id, slug, name, level) VALUES (1, 'gestor', 'Gestor', 50), (2, 'sindico', 'Síndico', 20), (3, 'leitor', 'Leitor', 10)");
        $this->pdo->exec("INSERT INTO access_permissions (id, slug) VALUES (1, 'erp.access'), (2, 'erp.reports.view')");
        $this->pdo->exec('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (1, 1), (2, 1), (3, 2)');
        $user = $this->pdo->prepare("INSERT INTO users (id, first_name, last_name, email, document, status) VALUES (?, 'Fulano', 'de Tal', ?, '123.456.789-00', ?)");
        foreach ([[7, 'confirmed'], [11, 'confirmed'], [12, 'confirmed'], [13, 'registered'], [14, 'confirmed'], [15, 'confirmed'], [16, 'confirmed'], [20, 'confirmed']] as [$id, $status]) {
            $user->execute([$id, "fulano{$id}@exemplo.com", $status]);
        }
        foreach ([[7, 1], [11, 1], [12, 1], [13, 1], [14, 1], [15, 1], [16, 1], [16, 3], [20, 3]] as [$id, $condominium]) {
            $this->pdo->prepare('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (?, ?)')->execute([$id, $condominium]);
        }
        foreach ([[7, 1], [11, 2], [12, 3], [14, 3], [15, 2], [16, 1], [16, 3], [20, 2]] as [$id, $role]) {
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        }
        foreach ([[14, 1, 'allow'], [15, 1, 'deny'], [15, 2, 'allow'], [20, 1, 'deny']] as $row) {
            $this->pdo->prepare('INSERT INTO access_user_overrides (user_id, permission_id, effect) VALUES (?, ?, ?)')->execute($row);
        }
        $this->service = new UserAccessReport($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testTopRolePureRule(): void
    {
        $known = ['gestor' => 0, 'sindico' => 0, 'leitor' => 0];
        self::assertSame('gestor', UserAccessReport::topRole(['leitor' => 10, 'gestor' => 50], $known), 'Vale o de maior nível.');
        self::assertSame('a', UserAccessReport::topRole(['b' => 5, 'a' => 5], ['a' => 0, 'b' => 0]), 'Empate: o menor slug.');
        self::assertNull(UserAccessReport::topRole([], $known));
        self::assertNull(UserAccessReport::topRole(['apagado' => 99], $known), 'Papel que não existe mais não conta.');
    }

    public function testSummaryFromTheCondominium(): void
    {
        $report = $this->service->build($this->user(), '1');
        self::assertSame(['gestor', 'sindico', 'leitor'], array_column($report['rows'], 'role'), 'Do maior nível para o menor.');
        self::assertSame([2, 2, 2], array_column($report['rows'], 'users'), 'O 16 tem dois papéis e conta só no de maior nível.');
        self::assertSame(['users' => 7, 'with_role' => 6, 'without_role' => 1], $report['totals']);
        self::assertSame($report['totals']['users'], array_sum(array_column($report['rows'], 'users')) + $report['totals']['without_role']);
        self::assertSame(['with' => 4, 'without' => 3], $report['erp_access'], 'Sem papel e papel sem erp.access não entram; allow concede; deny vence.');
        self::assertSame($report['totals']['users'], array_sum($report['erp_access']));
        self::assertSame([[12, 13, 15], false], [$report['without_erp_access_ids'], $report['without_erp_access_truncated']]);
        self::assertSame([[13], false], [$report['without_role_ids'], $report['without_role_truncated']]);
        self::assertSame([[14, 15], false], [$report['with_overrides_ids'], $report['with_overrides_truncated']], 'A exceção do usuário de fora não entra.');
        self::assertSame(ErpAccess::EFFECTS, array_column($report['overrides']['by_effect'], 'effect'), 'Todos os efeitos, sempre.');
        $effects = array_combine(array_column($report['overrides']['by_effect'], 'effect'), $report['overrides']['by_effect']);
        self::assertSame([2, [2, 2], [1, 1]], [$report['overrides']['users'], [$effects['allow']['overrides'], $effects['allow']['users']],
            [$effects['deny']['overrides'], $effects['deny']['users']]]);
        self::assertSame([['status' => 'confirmed', 'users' => 6], ['status' => 'registered', 'users' => 1]], $report['by_status']);
        self::assertSame($report['totals']['users'], array_sum(array_column($report['by_status'], 'users')));
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'de Tal', 'exemplo', '123.456'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCutAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= UserAccessReport::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'status' => 'confirmed', 'roles' => [], 'overrides' => []];
        }
        $report = UserAccessReport::summarize($many, [], []);
        self::assertCount(UserAccessReport::MAX_IDS, $report['without_role_ids']);
        self::assertTrue($report['without_role_truncated']);
        self::assertSame(['with' => 0, 'without' => UserAccessReport::MAX_IDS + 1], $report['erp_access'], 'Sem papel nunca entra (fail-closed).');
        $empty = UserAccessReport::summarize([], [['slug' => 'x', 'name' => 'X', 'level' => 1]], ['x']);
        self::assertSame([['role' => 'x', 'role_name' => 'X', 'level' => 1, 'users' => 0]], $empty['rows'], 'Sem usuário, os papéis aparecem zerados.');
    }

    public function testValidationAndScope(): void
    {
        foreach ([null, '', 0, 'abc', '-1', []] as $value) {
            try {
                $this->service->build($this->user(), $value);
                self::fail('Condomínio inválido passou: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3);
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/UserAccessReport.php');
        foreach (['$this->scope->assertAccess(', 'ErpAccess::effective(', 'ErpAccess::EFFECTS'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'first_name', 'last_name', 'email', 'document', 'phone', 'password', 'two_factor'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem nome, e-mail, documento, senha ou 2FA: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/UserAccessReport.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.users.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/user-access", "UserAccessReport:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/notifications/cancel-stale"'), strpos($index, $route), 'Logo depois do cancelamento dos avisos parados.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /notifications/cancel-stale:\n"), strpos($openapi, "  /reports/user-access:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
