<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommitmentsPosition;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0238: posição dos empenhos com os registros do cadastro; somente leitura e sem descrição nem fornecedor. */
final class CommitmentsPositionTest extends TestCase
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

    public function testPositionFromTheRegistry(): void
    {
        $this->commitment('2026-10-02', null, '100,00', 'active');
        $this->commitment('2026-10-03', '2026-10-10', '50,00', 'settled');
        $this->commitment('2026-10-05', '2026-10-25', '70,00', 'settled');
        $this->commitment('2026-10-07', null, '40,00', 'inactive');
        $this->commitment('2026-09-15', null, '80,00', 'active');   // em aberto de antes: só na idade
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new CommitmentsPosition($this->pdo))->build($this->admin, $this->condominium, '2026-10-01', '2026-10-31');
        self::assertSame([['active', 1, 10000], ['settled', 2, 12000], ['inactive', 1, 4000]],
            array_map(static fn (array $r): array => [$r['status'], $r['commitments'], $r['amount_cents']], $report['rows']));
        self::assertSame(['commitments' => 4, 'amount_cents' => 26000], $report['totals']);
        self::assertSame([2, 0, 2, 27, 13.5, 20], [$report['settlement']['count'], $report['settlement']['with_entry'], $report['settlement']['without_entry'],
            $report['settlement']['days_total'], $report['settlement']['avg_days'], $report['settlement']['max_days']]);
        self::assertSame(['commitments' => 2, 'amount_cents' => 18000], $report['open']['total']);
        self::assertSame(['commitments' => 1, 'amount_cents' => 8000], $report['open']['by_age']['de_31_a_60']);
        self::assertStringNotContainsString('Empenho de teste', (string)json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CommitmentsPosition($this->pdo))->build($limited, $this->condominium, '2026-10-01', '2026-10-31');
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function commitment(string $on, ?string $settled, string $amount, string $status): void
    {
        $this->registry->save($this->admin, 'commitments', ['condominium_id' => $this->condominium, 'description' => 'Empenho de teste', 'amount_cents' => $amount,
            'committed_on' => $on, 'settled_on' => $settled, 'status' => $status]);
    }
}
