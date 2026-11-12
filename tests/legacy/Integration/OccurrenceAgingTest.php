<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OccurrenceAging;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0256: idade das ocorrências em aberto com as ocorrências do cadastro; somente leitura e sem dado da ocorrência. */
final class OccurrenceAgingTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testAgingFromTheRegistry(): void
    {
        $this->occurrence(-10, 'alta');
        $running = $this->occurrence(-60, 'media');
        $this->pdo->prepare("UPDATE erp_occurrences SET status = 'running' WHERE id = ?")->execute([$running]);
        $this->occurrence(-200, 'baixa');
        $done = $this->occurrence(-300, 'alta');
        $this->pdo->prepare("UPDATE erp_occurrences SET status = 'done' WHERE id = ?")->execute([$done]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new OccurrenceAging($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['open' => 2, 'running' => 1, 'age_0_30' => 1, 'age_31_90' => 1, 'age_over_90' => 1, 'with_ticket' => 0, 'stale_without_ticket' => 1,
            'done' => 1], $report['totals']);
        self::assertSame((new \DateTimeImmutable('-200 days'))->format('Y-m-d'), $report['oldest_open_on']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Ocorrência de teste', 'Relato de teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new OccurrenceAging($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function occurrence(int $days, string $priority): int
    {
        return (int)$this->registry->save($this->admin, 'occurrences', ['condominium_id' => $this->condominium, 'subject' => 'Ocorrência de teste',
            'priority' => $priority, 'occurred_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d'), 'description' => 'Relato de teste'])['id'];
    }
}
