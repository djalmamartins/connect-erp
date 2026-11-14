<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\AssemblyDraftCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0291: rascunhos de assembleia esquecidos cancelados em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE e ganha NOW()) para a regra pura (o limite da primeira convocação), a validação, o
 * escopo, as recusas (uma por caso, tudo desfeito), a prévia, a gravação igual à do cancelamento de uma, só nos rascunhos
 * vencidos deste condomínio, e o contrato. Os tipos vêm de Assemblies::KINDS. O MariaDB confere com assembleias de verdade
 * (Integration\AssemblyDraftCancellationTest).
 */
final class AssemblyDraftCancellationTest extends TestCase
{
    private const NOW = '2026-11-14 12:00:00';

    private PDO $pdo;
    private AssemblyDraftCancellation $service;

    protected function setUp(): void
    {
        $now = static fn (): string => self::NOW;
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
            'CREATE TABLE erp_assemblies (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, title TEXT, location TEXT, first_call_at TEXT, status TEXT,
                announcement_id INTEGER NULL, cancel_reason TEXT NULL, created_by INTEGER, updated_by INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b] = [Assemblies::KINDS[0], Assemblies::KINDS[count(Assemblies::KINDS) - 1]];
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $insert = $this->pdo->prepare("INSERT INTO erp_assemblies (id, condominium_id, kind, title, location, first_call_at, status, created_by)
            VALUES (?, ?, ?, 'Obra da piscina do Fulano', 'Rua do Fulano, 10', ?, ?, 3)");
        foreach ([[1, 1, $a, '2026-11-01 10:00:00', 'draft'], [2, 1, $b, '2026-10-01 19:00:00', 'draft'], [3, 1, $a, '2026-11-20 19:00:00', 'draft'],
            [4, 1, $a, '2026-10-01 19:00:00', 'convened'], [5, 1, $a, '2026-10-01 19:00:00', 'cancelled'], [6, 1, $a, '2026-10-01 19:00:00', 'closed'],
            [7, 1, $a, self::NOW, 'draft'], [8, 3, $a, '2026-10-01 19:00:00', 'draft']] as $row) {
            $insert->execute($row);
        }
        $this->service = new AssemblyDraftCancellation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemRule(): void
    {
        $draft = ['status' => 'draft', 'first_call_at' => '2026-11-14 11:59:59'];
        self::assertNull(AssemblyDraftCancellation::problem($draft, self::NOW));
        self::assertNotNull(AssemblyDraftCancellation::problem(['first_call_at' => self::NOW] + $draft, self::NOW), 'Na hora exata ainda não passou.');
        self::assertNotNull(AssemblyDraftCancellation::problem(null, self::NOW));
        foreach (['convened', 'open', 'closed', 'cancelled'] as $status) {
            self::assertNotNull(AssemblyDraftCancellation::problem(['status' => $status] + $draft, self::NOW), $status);
        }
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Rascunhos esquecidos'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['assembly_ids' => []] + $ok, ['assembly_ids' => 'x'] + $ok, ['assembly_ids' => ['a']] + $ok, ['assembly_ids' => [0]] + $ok,
            ['assembly_ids' => range(1, AssemblyDraftCancellation::MAX_ASSEMBLIES + 1)] + $ok] as $input) {
            try {
                $this->service->cancel($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('draft', $this->assemblyStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->cancel($this->user(), ['condominium_id' => 3] + $ok);
    }

    public function testDryRunThenCancelTheStaleDrafts(): void
    {
        $input = ['condominium_id' => '1', 'reason' => 'Rascunhos esquecidos', 'dry_run' => true];
        $preview = $this->service->cancel($this->user(), $input);
        self::assertSame([[1, 2], 0, true, self::NOW], [array_column($preview['assemblies'], 'id'), $preview['changed'], $preview['dry_run'], $preview['now']]);
        self::assertSame(['2026-11-01', 13], [$preview['assemblies'][0]['first_call_on'], $preview['assemblies'][0]['days_past']]);
        self::assertSame(['draft', 'draft'], [$this->assemblyStatus(1), $this->assemblyStatus(2)], 'A prévia desfaz.');

        $done = $this->service->cancel($this->user(), ['dry_run' => false] + $input);
        self::assertSame([2, 2], [$done['count'], $done['changed']]);
        self::assertSame(Assemblies::KINDS, array_column($done['by_kind'], 'kind'));
        self::assertSame($done['count'], array_sum(array_column($done['by_kind'], 'assemblies')), 'O total é a soma dos tipos.');
        $row = $this->pdo->query('SELECT status, cancel_reason, updated_by FROM erp_assemblies WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['cancelled', 'Rascunhos esquecidos', 7], [$row['status'], $row['cancel_reason'], (int)$row['updated_by']], 'A mesma gravação do cancelamento de uma.');
        self::assertSame(['draft', 'convened', 'draft', 'draft'], [$this->assemblyStatus(3), $this->assemblyStatus(4), $this->assemblyStatus(7), $this->assemblyStatus(8)],
            'O futuro, o convocado, o da hora exata e o de outro condomínio ficam.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'piscina', 'Rua', 'esquecidos', 'title', 'location'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $again = $this->service->cancel($this->user(), $input);
        self::assertSame(0, $again['count'], 'Sem rascunho vencido, nada entra.');
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach ([3 => 'ainda não passou', 4 => 'edital', 5 => 'Só rascunhos', 6 => 'Só rascunhos', 7 => 'ainda não passou', 8 => 'não encontrada',
            999 => 'não encontrada'] as $bad => $text) {
            try {
                $this->service->cancel($this->user(), ['condominium_id' => 1, 'reason' => 'Rascunhos esquecidos', 'assembly_ids' => [1, $bad]]);
                self::fail("A assembleia {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_assembly']);
                self::assertStringContainsString($text, $exception->errors['_form']);
            }
            self::assertSame('draft', $this->assemblyStatus(1), 'Tudo ou nada.');
        }
        $done = $this->service->cancel($this->user(), ['condominium_id' => 1, 'reason' => 'Rascunhos esquecidos', 'assembly_ids' => ['2']]);
        self::assertSame([[2], 'draft'], [array_column($done['assemblies'], 'id'), $this->assemblyStatus(1)], 'Com ids, só os informados.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $single = (string)file_get_contents($root . '/source/Services/Erp/Assemblies.php');
        foreach (["UPDATE erp_assemblies SET status = 'cancelled', cancel_reason = :r, updated_by = :u", "Announcements::line(\$reason, 5, 255, 'o motivo do cancelamento')",
            "Audit::record('update', 'erp_assemblies', \$id, ['status' => \$assembly['status']], ['status' => 'cancelled', 'cancel_reason' => \$reason, 'notice_stopped'"] as $needle) {
            self::assertStringContainsString($needle, $single, 'O cancelamento de uma: ' . $needle);
        }
        $sql = (string)file_get_contents($root . '/database/migrations/' . self::migration($root));
        self::assertMatchesRegularExpression("/`status` enum\\('draft','convened','open','closed','cancelled'\\)/", $sql);
        $service = (string)file_get_contents($root . '/source/Services/Erp/AssemblyDraftCancellation.php');
        foreach (['$this->scope->assertAccess(', "Announcements::line(\$input['reason'] ?? null, 5, 255, 'o motivo do cancelamento')", 'SELECT NOW()', 'FOR UPDATE',
            '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', "Audit::record('update', 'erp_assemblies'", "AND status = 'draft'",
            'rowCount() !== 1', 'Assemblies::KINDS'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'title', 'location', 'created_by', 'Announcements(', '->convene(', '->close('] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar, sem título, local nem edital: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/AssemblyDraftCancellation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.assemblies.view', \$user)", "Access::can('erp.assemblies.manage', \$user)",
            'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $assemblies = (string)file_get_contents($root . '/source/Controllers/Api/V1/Assemblies.php');
        self::assertStringContainsString("Access::can('erp.assemblies.manage', \$user)", $assemblies, 'As mesmas permissões de cancelar uma.');
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/assemblies/cancel-stale-drafts", "AssemblyDraftCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/discounts"'), strpos($index, $route), 'Logo depois do relatório de descontos.');
        self::assertLessThan(strpos($index, '"/assemblies/{id}"'), strpos($index, $route), 'Antes das rotas /assemblies/{id}.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/discounts:\n"), strpos($openapi, "  /assemblies/cancel-stale-drafts:\n"));
    }

    /** A migration que cria erp_assemblies. */
    private static function migration(string $root): string
    {
        foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
            if (str_contains((string)file_get_contents($file), 'CREATE TABLE IF NOT EXISTS `erp_assemblies`')) {
                return basename($file);
            }
        }
        self::fail('Migration de erp_assemblies não encontrada.');
    }

    private function assemblyStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_assemblies WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
