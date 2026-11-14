<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AssemblyHistory;

/**
 * ADR 0264: histórico das assembleias. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura (por
 * tipo: encerradas, canceladas e em andamento, presença só das encerradas, procurações, sem direito a voto, itens
 * aprovados e não aprovados; total = soma das linhas), a consulta sem dado da assembleia nem dos presentes, a validação do
 * período, o escopo e o contrato; as assembleias gravadas pelo fluxo são conferidas no MariaDB
 * (Integration\AssemblyHistoryTest).
 */
final class AssemblyHistoryTest extends TestCase
{
    private PDO $pdo;
    private AssemblyHistory $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_assemblies (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, mode TEXT, title TEXT, location TEXT, first_call_at TEXT, status TEXT,
                closed_at TEXT, minutes TEXT)',
            'CREATE TABLE erp_assembly_items (id INTEGER PRIMARY KEY, assembly_id INTEGER, title TEXT, status TEXT, result_json TEXT)',
            'CREATE TABLE erp_assembly_attendance (id INTEGER PRIMARY KEY, assembly_id INTEGER, unit_id INTEGER, attendee_name TEXT, is_proxy INTEGER, eligible INTEGER)']
            as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new AssemblyHistory($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $a = static fn (int $id, string $kind, string $status, ?string $closed = null): array => ['id' => $id, 'kind' => $kind, 'status' => $status, 'closed_on' => $closed];
        $p = static fn (int $assembly, int $unit, bool $proxy = false, bool $eligible = true): array => ['assembly_id' => $assembly, 'unit_id' => $unit, 'is_proxy' => $proxy,
            'eligible' => $eligible];
        $i = static fn (int $assembly, string $status, ?bool $approved): array => ['assembly_id' => $assembly, 'status' => $status, 'approved' => $approved];
        $summary = AssemblyHistory::summarize([$a(1, 'ordinaria', 'closed', '2026-04-10'), $a(2, 'ordinaria', 'cancelled'), $a(3, 'extraordinaria', 'closed', '2026-08-01'),
            $a(4, 'extraordinaria', 'open'), $a(5, 'xyz', 'draft')],
            [$p(1, 10, true), $p(1, 11, false, false), $p(2, 10), $p(3, 10), $p(3, 12, true), $p(4, 10)],
            [$i(1, 'decided', true), $i(1, 'decided', false), $i(1, 'pending', null), $i(3, 'decided', true), $i(2, 'decided', true), $i(4, 'decided', false), $i(4, 'decided', null)]);
        self::assertSame(['ordinaria', 'extraordinaria'], array_column($summary['rows'], 'kind'), 'Os tipos das assembleias.');
        self::assertSame([3, 2], array_column($summary['rows'], 'assemblies'), 'Tipo desconhecido conta como ordinária.');
        self::assertSame([1, 1], array_column($summary['rows'], 'closed'));
        self::assertSame([1, 0], array_column($summary['rows'], 'cancelled'));
        self::assertSame([1, 1], array_column($summary['rows'], 'pending'));
        self::assertSame([2, 2], array_column($summary['rows'], 'attended_units'), 'Presença só das encerradas.');
        self::assertSame([1, 1], array_column($summary['rows'], 'proxies'));
        self::assertSame([1, 0], array_column($summary['rows'], 'ineligible'));
        self::assertSame([2, 3], array_column($summary['rows'], 'items_decided'), 'Itens da cancelada e os pendentes não contam.');
        self::assertSame([1, 1], array_column($summary['rows'], 'items_approved'));
        self::assertSame([1, 2], array_column($summary['rows'], 'items_rejected'), 'Sem resultado conta como não aprovado.');
        self::assertSame([2.0, 2.0], array_column($summary['rows'], 'avg_attended_units'));
        foreach (AssemblyHistory::COUNTS as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame([2.0, '2026-08-01'], [$summary['totals']['avg_attended_units'], $summary['last_closed_on']]);
        $empty = AssemblyHistory::summarize([], [], []);
        self::assertSame([null, null], [$empty['totals']['avg_attended_units'], $empty['last_closed_on']]);
    }

    public function testBuildWithoutAssemblyOrAttendeeData(): void
    {
        $this->pdo->exec("INSERT INTO erp_assemblies (id, condominium_id, kind, mode, title, location, first_call_at, status, closed_at, minutes) VALUES
            (10, 1, 'ordinaria', 'presencial', 'AGO de teste', 'Salão de festas', '2026-03-10 19:00:00', 'closed', '2026-03-10 22:00:00', 'Ata reservada'),
            (11, 1, 'extraordinaria', 'virtual', 'AGE de teste', 'https://sala.example.invalid', '2026-12-01 19:00:00', 'convened', NULL, NULL),
            (12, 3, 'ordinaria', 'presencial', 'De fora', 'Fora', '2026-03-10 19:00:00', 'closed', '2026-03-10 22:00:00', NULL),
            (13, 1, 'ordinaria', 'presencial', 'Antiga', 'Salão', '2010-01-01 19:00:00', 'closed', '2010-01-01 22:00:00', NULL)");
        $this->pdo->exec("INSERT INTO erp_assembly_attendance (assembly_id, unit_id, attendee_name, is_proxy, eligible) VALUES (10, 40, 'Fulano de Tal', 1, 1),
            (10, 41, 'Beltrana', 0, 0), (12, 50, 'De fora', 0, 1), (13, 40, 'Antigo', 0, 1)");
        $this->pdo->exec("INSERT INTO erp_assembly_items (assembly_id, title, status, result_json) VALUES (10, 'Aprovação das contas', 'decided',
            '{\"approved\":true,\"yes\":3,\"no\":0,\"abstain\":0,\"total\":3,\"quorum\":\"simple\"}'), (11, 'Obra', 'pending', NULL)");
        $report = $this->service->build($this->user(), '1', '2026-01-01', '2026-12-31');
        self::assertSame([1, ['assemblies' => 2, 'closed' => 1, 'cancelled' => 0, 'pending' => 1, 'attended_units' => 2, 'proxies' => 1, 'ineligible' => 1, 'items_decided' => 1,
            'items_approved' => 1, 'items_rejected' => 0, 'avg_attended_units' => 2.0], '2026-03-10'], [$report['condominium_id'], $report['totals'], $report['last_closed_on']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['AGO de teste', 'Salão', 'example.invalid', 'Ata reservada', 'Fulano', 'Beltrana', 'Aprovação', 'unit_id', 'title'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado da assembleia nem dos presentes: ' . $needle);
        }
        $default = $this->service->build($this->user(), 1);
        self::assertSame([date('Y-m-d', strtotime('-730 days')), date('Y-m-d', strtotime('+365 days'))], [$default['from'], $default['to']], 'Período padrão.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [0, null, null], [1, '2026-02-30', null], [1, null, '14/11/2026'], [1, '2026-12-01', '2026-11-01'],
            [1, '2000-01-01', '2026-11-14'], [1, ['x'], null]] as [$c, $from, $to]) {
            try {
                $this->service->build($this->user(), $c, $from, $to);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $from, $to]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/AssemblyHistory.php');
        foreach (['$this->scope->assertAccess(', 'Assemblies::KINDS', 'FROM erp_assemblies WHERE condominium_id = ? AND first_call_at >= ?'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'attendee_name', 'minutes', 'location', 'SELECT title', ', title', 'erp_assembly_votes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado da assembleia nem dos presentes: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/AssemblyHistory.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.assemblies.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/assembly-history", "AssemblyHistory:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
