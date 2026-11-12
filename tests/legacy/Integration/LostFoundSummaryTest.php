<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\LostFoundSummary;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0232: resumo dos achados e perdidos com os itens do cadastro; somente leitura e sem dado do item. */
final class LostFoundSummaryTest extends TestCase
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

    public function testSummaryFromTheRegistry(): void
    {
        $this->item('2026-10-02', null, 'active');
        $this->item('2026-10-03', '2026-10-05', 'returned');
        $this->item('2026-10-10', '2026-10-20', 'returned');
        $this->item('2026-10-04', null, 'discarded');
        $this->item('2026-09-01', null, 'active');    // guardado de antes (60 dias em 31/10): só na idade
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new LostFoundSummary($this->pdo))->build($this->admin, $this->condominium, '2026-10-01', '2026-10-31');
        self::assertSame([['active', 1], ['returned', 2], ['discarded', 1]], array_map(static fn (array $r): array => [$r['status'], $r['items']], $report['rows']));
        self::assertSame(4, $report['totals']['items']);
        self::assertSame([2, 2, 12, 6.0, 10], [$report['returns']['count'], $report['returns']['dated'], $report['returns']['days_total'], $report['returns']['avg_days'],
            $report['returns']['max_days']]);
        self::assertSame(['ate_30' => 1, 'de_31_a_90' => 1, 'de_91_a_180' => 0, 'mais_de_180' => 0, 'total' => 2], $report['custody']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Item de teste', 'Morador de teste', 'Porteiro de teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new LostFoundSummary($this->pdo))->build($limited, $this->condominium, '2026-10-01', '2026-10-31');
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function item(string $found, ?string $returned, string $status): void
    {
        $this->registry->save($this->admin, 'lost_found', ['condominium_id' => $this->condominium, 'item' => 'Item de teste', 'found_on' => $found,
            'found_by' => 'Porteiro de teste', 'returned_on' => $returned, 'returned_to' => $returned !== null ? 'Morador de teste' : null, 'status' => $status]);
    }
}
