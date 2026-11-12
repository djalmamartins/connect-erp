<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InsuranceCoverage;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0244: cobertura de seguros com as apólices do cadastro; somente leitura e sem dado da apólice. */
final class InsuranceCoverageTest extends TestCase
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

    public function testCoverageFromTheRegistry(): void
    {
        $this->policy('AP-101', -400, -10, '900,00');
        $this->policy('AP-102', -355, 10, '1.200,00');
        $this->policy('AP-103', 11, 376, '1.300,00');
        $cancelled = $this->policy('AP-104', -100, 200, '500,00');
        $this->pdo->prepare("UPDATE erp_insurances SET status = 'inactive' WHERE id = ?")->execute([$cancelled]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new InsuranceCoverage($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([1, 1, 0, 0, 1], array_column($report['rows'], 'policies'));
        self::assertSame([90000, 120000, 0, 0, 130000], array_column($report['rows'], 'premium_cents'));
        self::assertSame(['policies' => 3, 'premium_cents' => 340000, 'coverage_cents' => 0, 'without_pdf' => 3], $report['totals']);
        self::assertSame([true, (new \DateTimeImmutable('376 days'))->format('Y-m-d'), 1], [$report['covered'], $report['covered_until'], $report['cancelled']],
            'A renovação emenda no dia seguinte ao fim da atual.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Seguradora Teste', 'AP-10'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $later = (new InsuranceCoverage($this->pdo))->build($this->admin, $this->condominium, (new \DateTimeImmutable('400 days'))->format('Y-m-d'));
        self::assertSame([false, null, 3], [$later['covered'], $later['covered_until'], $later['rows'][0]['policies']], 'Daqui a 400 dias, descoberto.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new InsuranceCoverage($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function policy(string $number, int $startDays, int $endDays, string $premium): int
    {
        return (int)$this->registry->save($this->admin, 'insurances', ['condominium_id' => $this->condominium, 'insurer' => 'Seguradora Teste', 'policy_number' => $number,
            'starts_on' => (new \DateTimeImmutable("{$startDays} days"))->format('Y-m-d'), 'ends_on' => (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d'),
            'premium_cents' => $premium])['id'];
    }
}
