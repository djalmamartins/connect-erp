<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\ImportsOverview;

/** ADR 0284: situação das importações com linhas de erp_imports no MariaDB e o relógio do banco; somente leitura e sem arquivo, caminho nem hash. */
final class ImportsOverviewTest extends TestCase
{
    private int $condominium;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredImports(): void
    {
        $kinds = array_keys(Imports::KINDS);
        [$first, $last] = [$kinds[0], $kinds[count($kinds) - 1]];
        $recent = $this->import($first, 'previewed', 0, 12, 0);
        $old = $this->import($last, 'previewed', 45, 7, 2);
        $applied = $this->import($first, 'applied', 10, 30, 0);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ImportsOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame($kinds, array_column($report['rows'], 'kind'), 'Os tipos vêm de Imports::KINDS.');
        self::assertSame([3, 2, 1, 49, 2, 1], [$report['totals']['imports'], $report['totals']['previewed'], $report['totals']['applied'], $report['totals']['rows'],
            $report['totals']['errors'], $report['totals']['with_errors']]);
        $expected = array_fill_keys(array_keys(ImportsOverview::AGES), 0);
        $expected[ImportsOverview::age(0)]++;
        $expected[ImportsOverview::age(45)]++;
        self::assertSame($expected, $report['pending_by_age'], 'A idade vem do relógio do banco.');
        self::assertSame([$old, $recent], $report['pending_ids'], 'Da prévia mais antiga para a mais nova.');
        self::assertSame([$old], $report['with_errors_ids']);
        self::assertNotNull($report['totals']['last_applied_on']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        self::assertSame('applied', $this->pdo->query("SELECT status FROM erp_imports WHERE id = {$applied}")->fetchColumn());
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['planilha-fulano', 'storage/private', 'hash-teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ImportsOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function import(string $kind, string $status, int $daysAgo, int $rows, int $errors): int
    {
        $this->pdo->prepare("INSERT INTO erp_imports (condominium_id, kind, original_name, sha256, storage_path, size_bytes, status, rows_count, error_count, created_by,
                created_at, applied_at)
            VALUES (?, ?, 'planilha-fulano.csv', ?, 'storage/private/imports/x.csv', 100, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), ?)")
            ->execute([$this->condominium, $kind, hash('sha256', 'hash-teste' . bin2hex(random_bytes(6))), $status, $rows, $errors, $this->admin->id, $daysAgo,
                $status === 'applied' ? date('Y-m-d H:i:s') : null]);
        return (int)$this->pdo->lastInsertId();
    }
}
