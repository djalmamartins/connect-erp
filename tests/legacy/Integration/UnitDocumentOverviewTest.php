<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\UnitDocumentOverview;
use Source\Services\Erp\UnitDocuments;

/** ADR 0292: documentos da unidade com linhas de erp_unit_documents no MariaDB e os meses do relógio do banco; somente leitura e sem código nem conteúdo. */
final class UnitDocumentOverviewTest extends TestCase
{
    private int $condominium;
    private int $unit;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO erp_units (condominium_id, block, number) VALUES (?, ?, ?)')->execute([$this->condominium, 'A', '101']);
        $this->unit = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testOverviewFromStoredDocuments(): void
    {
        $kinds = array_keys(UnitDocuments::ALL_KINDS);
        $this->document($kinds[0], 'debts', 1200, 'issued', 0);
        $this->document($kinds[0], 'clear', 0, 'issued', 0);
        $cancelled = $this->document($kinds[0], 'clear', 0, 'cancelled', 0);
        $this->document($kinds[0], 'debts', 300, 'issued', 70);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $current = (new UnitDocumentOverview($this->pdo))->build($this->admin, $this->condominium, '1');
        self::assertSame(substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 7), $current['to'], 'O mês do banco.');
        $rows = array_combine(array_column($current['rows'], 'kind'), $current['rows']);
        self::assertSame([2, 1, 1, 1, 1200, 1], [$rows[$kinds[0]]['issued'], $rows[$kinds[0]]['cancelled'], $rows[$kinds[0]]['clear'], $rows[$kinds[0]]['debts'],
            $rows[$kinds[0]]['debts_cents'], $rows[$kinds[0]]['units']], 'Um mês: o de 70 dias atrás fica de fora.');
        self::assertSame([$cancelled], $current['cancelled_ids']);
        $year = (new UnitDocumentOverview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([3, 3], [$year['totals']['issued'], array_sum(array_column($year['by_month'], 'issued'))]);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($year, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'VERIF'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new UnitDocumentOverview($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function document(string $kind, string $result, int $cents, string $state, int $daysAgo): int
    {
        $this->pdo->prepare("INSERT INTO erp_unit_documents (condominium_id, unit_id, kind, as_of, result, total_cents, payload_json, verification_code, status, issued_by,
                created_at) VALUES (?, ?, ?, CURDATE(), ?, ?, '{\"morador\":\"Fulano\"}', ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))")
            ->execute([$this->condominium, $this->unit, $kind, $result, $cents, 'VERIF' . strtoupper(bin2hex(random_bytes(5))) . 'X', $state, $this->admin->id, $daysAgo]);
        return (int)$this->pdo->lastInsertId();
    }
}
