<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MeterReadingReview;
use Source\Services\Erp\MeterReadings;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0220: conferência das leituras gravadas pelo MeterReadings::record; somente leitura e sem morador. */
final class MeterReadingReviewTest extends TestCase
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

    public function testFlagsSpikeMissingAndZero(): void
    {
        $leak = $this->meter('101', 'H-101');
        $steady = $this->meter('102', 'H-102');
        $silent = $this->meter('103', 'H-103');
        $stuck = $this->meter('104', 'H-104');
        $readings = new MeterReadings($this->pdo);
        foreach (['2026-07' => 10, '2026-08' => 20, '2026-09' => 30, '2026-10' => 40] as $competence => $value) {
            foreach ([$leak, $steady, $silent] as $meter) {
                $readings->record($this->admin, ['meter_id' => $meter, 'competence' => $competence, 'read_on' => $competence . '-28', 'reading' => (string)$value]);
            }
        }
        $readings->record($this->admin, ['meter_id' => $leak, 'competence' => '2026-11', 'read_on' => '2026-11-05', 'reading' => '75']);
        $readings->record($this->admin, ['meter_id' => $steady, 'competence' => '2026-11', 'read_on' => '2026-11-05', 'reading' => '50']);
        $readings->record($this->admin, ['meter_id' => $stuck, 'competence' => '2026-11', 'read_on' => '2026-11-05', 'reading' => '0']);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new MeterReadingReview($this->pdo))->build($this->admin, $this->condominium, '2026-11');
        self::assertSame([[$leak, ['spike'], 10.0, 3.5], [$silent, ['missing'], 10.0, null], [$stuck, ['zero'], null, null]],
            array_map(static fn (array $i): array => [$i['meter_id'], $i['issues'], $i['average'], $i['ratio']], $report['items']));
        self::assertSame(['meters' => 4, 'read' => 3, 'billed' => 0, 'short_history' => 1, 'missing' => 1], $report['totals']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $relaxed = (new MeterReadingReview($this->pdo))->build($this->admin, $this->condominium, '2026-11', '4');
        self::assertSame(0, $relaxed['summary']['spike'], 'Fator 4: 3,5× não é pico.');
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new MeterReadingReview($this->pdo))->build($limited, $this->condominium, '2026-11');
            self::fail('Consultou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function meter(string $number, string $unitNumber): int
    {
        $unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $unitNumber, 'type' => 'apartamento'])['id'];
        return $this->registry->save($this->admin, 'meters', ['condominium_id' => $this->condominium, 'unit_id' => $unit, 'kind' => 'agua', 'number' => $number])['id'];
    }
}
