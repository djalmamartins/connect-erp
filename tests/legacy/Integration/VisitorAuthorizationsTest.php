<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\VisitorAuthorizations;

/** ADR 0234: situação das autorizações com os visitantes do cadastro; somente leitura e sem dado pessoal. */
final class VisitorAuthorizationsTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testSituationsFromTheRegistry(): void
    {
        $this->visitor(true, null, 'active');
        $this->visitor(false, '2026-12-31', 'active');    // fora da janela de 30 dias em 15/11
        $this->visitor(false, '2026-11-20', 'active');    // vence na janela
        $this->visitor(false, '2026-10-01', 'active');    // vencido há 45 dias e ainda liberado
        $this->visitor(false, '2026-10-01', 'inactive');  // bloqueado
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new VisitorAuthorizations($this->pdo))->build($this->admin, $this->condominium, '2026-11-15', 30);
        self::assertSame(['permanent' => 1, 'valid' => 1, 'expiring' => 1, 'expired_active' => 1, 'no_date' => 0, 'blocked' => 1],
            array_combine(array_column($report['rows'], 'situation'), array_column($report['rows'], 'visitors')));
        self::assertSame(['visitors' => 5, 'active' => 4, 'attention' => 1], $report['totals']);
        self::assertSame(['ate_30' => 0, 'de_31_a_90' => 1, 'mais_de_90' => 0], $report['expired_by_age']);
        self::assertStringNotContainsString('Visitante de teste', (string)json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new VisitorAuthorizations($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function visitor(bool $permanent, ?string $until, string $status): void
    {
        $this->registry->save($this->admin, 'visitors', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'name' => 'Visitante de teste',
            'permanent_access' => $permanent ? '1' : '0', 'valid_until' => $until, 'status' => $status]);
    }
}
