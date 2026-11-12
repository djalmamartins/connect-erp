<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BoardComposition;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0254: composição do síndico e do conselho com os membros do cadastro; somente leitura e sem dado pessoal. */
final class BoardCompositionTest extends TestCase
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

    public function testCompositionFromTheRegistry(): void
    {
        $this->member('sindico', -365, 20);
        $this->member('fiscal', -365, 400);
        $old = $this->member('fiscal', -800, -10);
        $this->member('consultivo', 5, 700);
        $gone = $this->member('suplente', -365, 400);
        $this->pdo->prepare("UPDATE erp_board_members SET status = 'inactive' WHERE id = ?")->execute([$gone]);
        $this->pdo->prepare("UPDATE erp_board_members SET email = NULL WHERE id = ?")->execute([$old]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new BoardComposition($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['in_force' => 2, 'ending_0_30' => 1, 'ending_31_90' => 0, 'expired' => 1, 'not_started' => 1, 'inactive' => 1, 'incomplete' => 1],
            $report['totals']);
        self::assertSame([true, (new \DateTimeImmutable('20 days'))->format('Y-m-d'), ['subsindico', 'consultivo', 'suplente']],
            [$report['sindico_in_force'], $report['sindico_term_ends_on'], $report['vacant_roles']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Pessoa de teste', 'conselho@example.invalid', '11999990000'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new BoardComposition($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function member(string $role, int $startDays, int $endDays): int
    {
        return (int)$this->registry->save($this->admin, 'board', ['condominium_id' => $this->condominium, 'role' => $role, 'name' => 'Pessoa de teste',
            'term_starts_on' => (new \DateTimeImmutable("{$startDays} days"))->format('Y-m-d'), 'term_ends_on' => (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d'),
            'email' => 'conselho@example.invalid', 'phone' => '11999990000'])['id'];
    }
}
