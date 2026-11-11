<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\ResidentLinkEndings;
use Source\Services\Erp\ResidentLinkExpiry;

/** ADR 0242: vínculos por faixa de prazo com os vínculos do cadastro; somente leitura, sem dado pessoal e casando com o 0241. */
final class ResidentLinkEndingsTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102'] as $number) {
            $this->units[$number] = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testBucketsFromTheRegistry(): void
    {
        $this->link('101', 'owner', -100);
        $this->link('101', 'tenant', -40);
        $this->link('102', 'tenant', -10);
        $this->link('101', 'resident', 5);
        $this->link('101', 'financial', 45);
        $this->link('101', 'tenant', 80);
        $this->link('101', 'tenant', 200);
        $this->link('101', 'owner', null);
        $closed = $this->link('101', 'tenant', -20);
        $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive' WHERE id = ?")->execute([$closed]);
        $this->pdo->prepare("UPDATE erp_units SET status = 'inactive' WHERE id = ?")->execute([$this->units['102']]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ResidentLinkEndings($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([1, 1, 1, 1, 1, 1], array_column($report['rows'], 'links'));
        self::assertSame([0, 0, 1, 0, 0, 0], array_column($report['rows'], 'inactive_unit'));
        self::assertSame(['owner' => 1, 'tenant' => 3, 'resident' => 1, 'financial' => 1], $report['totals']['by_relationship']);
        self::assertSame([6, 2, 1, 1], [$report['totals']['links'], $report['closable'], $report['open_ended'], $report['later']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Pessoa de teste', '101', 'unit_label', 'ends_on'] as $needle) {
            self::assertStringNotContainsString($needle, str_replace((string)$this->condominium, '', $json), $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $preview = (new ResidentLinkExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Conferência', 'dry_run' => true]);
        self::assertSame([$report['closable'], $report['totals']['inactive_unit']], [$preview['count'], $preview['skipped_inactive_unit']],
            'closable casa com a prévia do encerramento em lote.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ResidentLinkEndings($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function link(string $unit, string $relationship, ?int $endDays): int
    {
        return (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit],
            'relationship' => $relationship, 'name' => 'Pessoa de teste', 'starts_on' => (new \DateTimeImmutable('-400 days'))->format('Y-m-d'),
            'ends_on' => $endDays !== null ? (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d') : null])['id'];
    }
}
