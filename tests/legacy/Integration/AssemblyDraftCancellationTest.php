<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Assemblies;
use Source\Services\Erp\AssemblyDraftCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0291: rascunhos de assembleia vencidos cancelados em lote no MariaDB, com o relógio do banco, as travas de linha e a auditoria só quando grava. */
final class AssemblyDraftCancellationTest extends TestCase
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

    public function testCancelsOnlyTheStaleDrafts(): void
    {
        $stale = $this->assembly('draft', -10);
        $future = $this->assembly('draft', 10);
        $convened = $this->assembly('convened', -10);
        $before = $this->audits();

        $preview = (new AssemblyDraftCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Rascunho esquecido',
            'dry_run' => true]);
        self::assertSame([[$stale], 0], [array_column($preview['assemblies'], 'id'), $preview['changed']]);
        self::assertSame([$before, 'draft'], [$this->audits(), $this->assemblyStatus($stale)], 'A prévia não grava nem audita.');

        try {
            (new AssemblyDraftCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Rascunho esquecido',
                'assembly_ids' => [$stale, $convened]]);
            self::fail('A convocada passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$convened, $exception->errors['_assembly']);
        }
        self::assertSame('draft', $this->assemblyStatus($stale), 'Tudo ou nada.');

        $done = (new AssemblyDraftCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Rascunho esquecido']);
        self::assertSame(1, $done['changed']);
        self::assertSame(['cancelled', 'draft', 'convened'], [$this->assemblyStatus($stale), $this->assemblyStatus($future), $this->assemblyStatus($convened)]);
        self::assertSame('Rascunho esquecido', $this->pdo->query("SELECT cancel_reason FROM erp_assemblies WHERE id = {$stale}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_assemblies' AND action = 'update' AND entity_id = '{$stale}'")
            ->fetchColumn());

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new AssemblyDraftCancellation($this->pdo))->cancel($limited, ['condominium_id' => $this->condominium, 'reason' => 'Rascunho esquecido']);
            self::fail('Fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function audits(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
    }

    private function assemblyStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_assemblies WHERE id = {$id}")->fetchColumn();
    }

    private function assembly(string $state, int $days): int
    {
        $this->pdo->prepare("INSERT INTO erp_assemblies (condominium_id, kind, mode, title, location, first_call_at, status, created_by)
            VALUES (?, ?, 'presencial', 'Obra do Fulano', 'Salão de festas', DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?)")
            ->execute([$this->condominium, Assemblies::KINDS[0], $days, $state, $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }
}
