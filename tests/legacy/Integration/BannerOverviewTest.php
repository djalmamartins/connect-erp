<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BannerOverview;

/** ADR 0286: situação dos banners com linhas de erp_banners no MariaDB e o dia do banco; somente leitura e sem título nem texto. */
final class BannerOverviewTest extends TestCase
{
    private int $condominium;
    private int $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $insert = $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)');
        $insert->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $insert->execute(['Beta', 'active']);
        $this->other = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredBanners(): void
    {
        $showing = $this->banner($this->condominium, -3, 3, 'active');
        $global = $this->banner(null, -1, 30, 'active');
        $scheduled = $this->banner($this->condominium, 2, 9, 'active');
        $ended = $this->banner($this->condominium, -20, -1, 'active');
        $this->banner($this->condominium, -3, 3, 'inactive');
        $this->banner($this->other, -3, 3, 'active');
        $deleted = $this->banner($this->condominium, -3, 3, 'active');
        $this->pdo->exec("UPDATE erp_banners SET deleted_at = NOW() WHERE id = {$deleted}");
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new BannerOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame((string)$this->pdo->query('SELECT CURDATE()')->fetchColumn(), $report['on'], 'O dia do banco.');
        $rows = array_combine(array_column($report['rows'], 'scope'), $report['rows']);
        self::assertSame([4, 1, 1, 1, 1], [$rows['condominium']['banners'], $rows['condominium']['showing'], $rows['condominium']['scheduled'],
            $rows['condominium']['ended'], $rows['condominium']['inactive']]);
        self::assertSame([1, 1], [$rows['global']['banners'], $rows['global']['showing']]);
        self::assertSame(5, $report['totals']['banners']);
        self::assertSame([$showing, $global], $report['showing_ids']);
        self::assertSame([$showing], $report['ending_soon_ids']);
        self::assertSame([$ended], $report['ended_active_ids']);
        self::assertNotContains($scheduled, $report['showing_ids']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Recado', 'Fulano'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new BannerOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function banner(?int $condominium, int $startDays, int $endDays, string $state): int
    {
        $this->pdo->prepare("INSERT INTO erp_banners (condominium_id, title, message, starts_on, ends_on, position, status, created_by)
            VALUES (?, 'Recado', 'Fale com Fulano', DATE_ADD(CURDATE(), INTERVAL ? DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY), NULL, ?, ?)")
            ->execute([$condominium, $startDays, $endDays, $state, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }
}
