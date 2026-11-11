<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OperationsSummary;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0210: resumo operacional do mês pelos cadastros reais (ocorrências, manutenções, reservas), somente leitura e no escopo. */
final class OperationsSummaryTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private int $condominium;
    private int $other;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->condominium = $this->condominiumNamed('Alpha');
        $this->other = $this->condominiumNamed('Beta');
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testSummaryFromTheRegistries(): void
    {
        $unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
        foreach ([['Vazamento na garagem', 'alta', '2026-10-05'], ['Barulho', 'media', '2026-10-20'], ['Portão travando', 'baixa', '2026-09-01']] as [$subject, $priority, $on]) {
            $this->registry->save($this->admin, 'occurrences', ['condominium_id' => $this->condominium, 'unit_id' => $unit, 'subject' => $subject, 'priority' => $priority,
                'occurred_on' => $on, 'description' => 'Relato do morador com dados que não podem sair no resumo.']);
        }
        foreach ([['Revisão do elevador', '2026-10-10', 'mensal'], ['Limpeza da caixa d\'água', '2026-10-25', 'semestral'], ['Jardim', '2026-11-20', 'mensal']] as [$service, $on, $f]) {
            $this->registry->save($this->admin, 'maintenances', ['condominium_id' => $this->condominium, 'service' => $service, 'planned_on' => $on, 'frequency' => $f]);
        }
        $this->pdo->exec("UPDATE erp_maintenances SET status = 'done', done_on = '2026-10-12' WHERE service = 'Revisão do elevador'");
        $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, 'Salão de festas', 'approval')")->execute([$this->condominium]);
        $area = (int)$this->pdo->lastInsertId();
        foreach ([['2026-10-03', 5000, 'confirmed'], ['2026-10-17', 5000, 'confirmed'], ['2026-11-20', 5000, 'active']] as [$on, $fee, $status]) {
            $this->pdo->prepare("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, fee_cents, status) VALUES (?, ?, ?, 'Fulano de Tal', ?, ?, ?)")
                ->execute([$this->condominium, $area, $unit, $on, $fee, $status]);
        }
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $r = (new OperationsSummary($this->pdo))->build($this->admin, $this->condominium, '2026-10', self::TODAY);
        self::assertSame([2, ['alta' => 1, 'media' => 1, 'baixa' => 0]], [$r['occurrences']['opened_in_month']['total'], $r['occurrences']['opened_in_month']['by_priority']]);
        self::assertSame([3, ['0-7' => 0, '8-30' => 1, '31+' => 2]], [$r['occurrences']['open_today']['total'], $r['occurrences']['open_today']['by_age']]);
        self::assertSame([2, 1, 1, [['Limpeza da caixa d\'água', 17]]], [$r['maintenances']['planned_in_month'], $r['maintenances']['done_in_month'],
            $r['maintenances']['next_30_days'], array_map(static fn (array $i): array => [$i['service'], $i['days_late']], $r['maintenances']['overdue_today']['items'])]);
        self::assertSame([2, 10000, 1, 'Salão de festas'], [$r['reservations']['confirmed_in_month'], $r['reservations']['fees_in_month_cents'],
            $r['reservations']['pending_approval_today'], $r['reservations']['by_area'][0]['area']]);
        $json = (string)json_encode($r);
        foreach (['Fulano', 'Relato', 'Vazamento'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
    }

    public function testScope(): void
    {
        try {
            (new OperationsSummary($this->pdo))->build($this->admin, $this->other, '2026-10', self::TODAY);
            self::fail('Resumiu condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function condominiumNamed(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }
}
