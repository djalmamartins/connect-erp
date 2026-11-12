<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BoardComposition;

/**
 * ADR 0254: composição do síndico e do conselho. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra
 * pura do resumo por cargo (em vigor, terminando em 30 e 90 dias, vencidos, a começar, inativos, incompletos; total = soma
 * das linhas), o síndico em vigor e os cargos vagos, a consulta sem dado pessoal, a validação, o escopo e o contrato; os
 * membros gravados pelo cadastro são conferidos no MariaDB (Integration\BoardCompositionTest).
 */
final class BoardCompositionTest extends TestCase
{
    private PDO $pdo;
    private BoardComposition $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_board_members (id INTEGER PRIMARY KEY, condominium_id INTEGER, role TEXT, name TEXT, document TEXT, unit_id INTEGER, unit_label TEXT,
                term_starts_on TEXT, term_ends_on TEXT, email TEXT, phone TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new BoardComposition($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $m = static fn (string $role, string $starts, string $ends, string $status = 'active', bool $complete = true): array => ['role' => $role, 'status' => $status,
            'starts_on' => $starts, 'ends_on' => $ends, 'complete' => $complete];
        $on = '2026-11-12';
        $summary = BoardComposition::summarize([$m('sindico', '2025-01-01', '2026-12-12'), $m('sindico', '2024-01-01', '2026-11-11'),
            $m('subsindico', '2025-01-01', '2027-01-20', 'active', false), $m('fiscal', '2025-01-01', '2026-11-12'), $m('fiscal', '2025-01-01', '2028-01-01'),
            $m('fiscal', '2026-11-13', '2028-11-12'), $m('consultivo', '2020-01-01', '2022-01-01', 'inactive'), $m('xyz', '2025-01-01', '2027-06-01')], $on);
        self::assertSame(['sindico', 'subsindico', 'fiscal', 'consultivo', 'suplente'], array_column($summary['rows'], 'role'), 'Os cargos vêm do cadastro.');
        self::assertSame([1, 1, 2, 0, 1], array_column($summary['rows'], 'in_force'), 'Desconhecido vira Suplente.');
        self::assertSame([1, 0, 1, 0, 0], array_column($summary['rows'], 'ending_0_30'), 'O último dia do mandato ainda vale.');
        self::assertSame([0, 1, 0, 0, 0], array_column($summary['rows'], 'ending_31_90'));
        self::assertSame([1, 0, 0, 0, 0], array_column($summary['rows'], 'expired'));
        self::assertSame([0, 0, 1, 0, 0], array_column($summary['rows'], 'not_started'));
        self::assertSame([0, 0, 0, 1, 0], array_column($summary['rows'], 'inactive'));
        self::assertSame([0, 1, 0, 0, 0], array_column($summary['rows'], 'incomplete'));
        foreach (array_keys($summary['totals']) as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame([true, '2026-12-12', ['consultivo']], [$summary['sindico_in_force'], $summary['sindico_term_ends_on'], $summary['vacant_roles']]);
        $empty = BoardComposition::summarize([$m('sindico', '2020-01-01', '2022-01-01')], $on);
        self::assertSame([false, null, ['sindico', 'subsindico', 'fiscal', 'consultivo', 'suplente']], [$empty['sindico_in_force'], $empty['sindico_term_ends_on'],
            $empty['vacant_roles']], 'Síndico vencido não está em vigor.');
    }

    public function testBuildWithoutPersonalData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_board_members (condominium_id, role, name, document, unit_label, term_starts_on, term_ends_on, email, phone, notes, status,
            deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'sindico', 'Fulano Síndico', '12345678909', '101', '2026-01-01', '2027-12-31', 'fulano@exemplo.com', '16994007100', 'Ata de 2026', 'active', null]);
        $insert->execute([1, 'fiscal', 'Beltrana', null, '202', '2026-01-01', '2027-12-31', '', '16994007101', null, 'active', null]);
        $insert->execute([3, 'fiscal', 'De fora', null, null, '2026-01-01', '2027-12-31', 'x@exemplo.com', '1', null, 'active', null]);
        $insert->execute([1, 'consultivo', 'Excluído', null, null, '2026-01-01', '2027-12-31', 'y@exemplo.com', '1', null, 'active', '2026-01-01 00:00:00']);
        $report = $this->service->build($this->user(), '1', '2026-11-12');
        self::assertSame([1, '2026-11-12', 2, 1, true, '2027-12-31', ['subsindico', 'consultivo', 'suplente']], [$report['condominium_id'], $report['on'],
            $report['totals']['in_force'], $report['totals']['incomplete'], $report['sindico_in_force'], $report['sindico_term_ends_on'], $report['vacant_roles']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Beltrana', '12345678909', 'exemplo.com', '16994007100', 'Ata', '101', 'name', 'phone'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
        self::assertSame(date('Y-m-d'), $this->service->build($this->user(), 1)['on'], 'Data padrão: hoje.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], ['1.5', null], [1, '2026-02-30'], [1, '12/11/2026'], [1, ['x']]] as [$c, $on]) {
            try {
                $this->service->build($this->user(), $c, $on);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $on]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/BoardComposition.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('board')", 'WHERE condominium_id = ? AND deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', ', name', 'document', 'notes', 'unit_'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/BoardComposition.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.board.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/board-composition", "BoardComposition:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
