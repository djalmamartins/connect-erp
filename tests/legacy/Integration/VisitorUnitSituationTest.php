<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VisitorUnitBlock;
use Source\Services\Erp\VisitorUnitSituation;

/** ADR 0266: visitantes pela situação da unidade, com os cadastros; somente leitura, sem dado pessoal e igual ao bloqueio em lote. */
final class VisitorUnitSituationTest extends TestCase
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
            $this->units[$number] = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        // A 102 tem morador ativo; a 101 ficou vazia (o vínculo foi encerrado).
        $resident = (int)$this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['101'], 'relationship' => 'tenant',
            'name' => 'Morador de teste', 'document' => '529.982.247-25'])['id'];
        $this->pdo->prepare("UPDATE erp_residents SET status = 'inactive' WHERE id = ?")->execute([$resident]);
        $this->registry->save($this->admin, 'residents', ['condominium_id' => $this->condominium, 'unit_id' => $this->units['102'], 'relationship' => 'owner',
            'name' => 'Morador de teste', 'document' => '111.444.777-35']);
    }

    public function testSituationFromTheRegistriesMatchesTheBatchBlock(): void
    {
        $this->visitor('101', true, null);
        $this->visitor('101', false, (new \DateTimeImmutable('+30 days'))->format('Y-m-d'));
        $this->visitor('102', true, null);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new VisitorUnitSituation($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['inhabited' => 1, 'empty' => 2, 'inactive_unit' => 0, 'unlinked' => 0],
            array_combine(array_column($report['rows'], 'group'), array_column($report['rows'], 'active')));
        self::assertSame(['units' => 2, 'visitors' => 3, 'active' => 3, 'permanent' => 2, 'blocked' => 0], $report['totals']);
        self::assertSame(['empty_active' => 2, 'blockable' => 2, 'incomplete' => 0], $report['attention']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Visitante de teste', 'Morador de teste', '12345678909', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $input = ['condominium_id' => $this->condominium, 'reason' => 'Família se mudou'];
        $preview = (new VisitorUnitBlock($this->pdo))->block($this->admin, $input + ['dry_run' => true]);
        self::assertSame([$report['attention']['blockable'], $report['attention']['incomplete']], [$preview['count'], $preview['skipped_incomplete']],
            'O relatório mostra o que o bloqueio em lote pegaria.');
        (new VisitorUnitBlock($this->pdo))->block($this->admin, $input);
        $after = (new VisitorUnitSituation($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([0, 2, 0], [$after['rows'][1]['active'], $after['rows'][1]['blocked'], $after['attention']['empty_active']]);

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VisitorUnitSituation($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function visitor(string $unit, bool $permanent, ?string $until): int
    {
        return (int)$this->registry->save($this->admin, 'visitors', ['condominium_id' => $this->condominium, 'unit_id' => $this->units[$unit], 'name' => 'Visitante de teste',
            'document' => '123.456.789-09', 'permanent_access' => $permanent ? '1' : '0', 'valid_until' => $until])['id'];
    }
}
