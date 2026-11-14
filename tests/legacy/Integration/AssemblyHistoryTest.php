<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AssemblyHistory;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0264: histórico das assembleias com as assembleias, presenças e itens gravados; somente leitura e sem dado da assembleia. */
final class AssemblyHistoryTest extends TestCase
{
    private int $condominium;
    private User $admin;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['101', '102', '103'] as $number) {
            $this->units[$number] = (int)$registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
    }

    public function testHistoryFromTheAssemblies(): void
    {
        $ago = $this->assembly('ordinaria', 'closed', '2026-04-10');
        $this->assembly('ordinaria', 'cancelled', null);
        $age = $this->assembly('extraordinaria', 'closed', '2026-08-01');
        foreach ([[$ago, '101', 1, 1], [$ago, '102', 0, 0], [$age, '101', 0, 1]] as [$assembly, $unit, $proxy, $eligible]) {
            $this->pdo->prepare("INSERT INTO erp_assembly_attendance (assembly_id, unit_id, attendee_name, is_proxy, eligible, checked_in_at) VALUES (?, ?, 'Presente de teste', ?, ?, NOW())")
                ->execute([$assembly, $this->units[$unit], $proxy, $eligible]);
        }
        foreach ([[$ago, 1, true], [$ago, 2, false], [$age, 1, true]] as [$assembly, $position, $approved]) {
            $this->pdo->prepare("INSERT INTO erp_assembly_items (assembly_id, position, title, vote_basis, quorum, status, result_json, decided_at)
                VALUES (?, ?, 'Item de teste', 'unit', 'simple', 'decided', ?, NOW())")->execute([$assembly, $position, json_encode(['approved' => $approved])]);
        }
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new AssemblyHistory($this->pdo))->build($this->admin, $this->condominium, '2026-01-01', '2026-12-31');
        self::assertSame(['assemblies' => 3, 'closed' => 2, 'cancelled' => 1, 'pending' => 0, 'attended_units' => 3, 'proxies' => 1, 'ineligible' => 1, 'items_decided' => 3,
            'items_approved' => 2, 'items_rejected' => 1, 'avg_attended_units' => 1.5], $report['totals']);
        self::assertSame(['2026-08-01', 2.0, 1.0], [$report['last_closed_on'], $report['rows'][0]['avg_attended_units'], $report['rows'][1]['avg_attended_units']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Assembleia de teste', 'Presente de teste', 'Item de teste', 'Local de teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new AssemblyHistory($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function assembly(string $kind, string $status, ?string $closedOn): int
    {
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, status, closed_at)
            VALUES (?, ?, 'presencial', 'Assembleia de teste', 'Local de teste', ?, ?, ?)")->execute([$this->condominium, $kind,
            ($closedOn ?? '2026-06-01') . ' 19:00:00', $status, $closedOn === null ? null : $closedOn . ' 22:00:00']);
        return (int)$this->pdo->lastInsertId();
    }
}
