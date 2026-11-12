<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContractClosing;
use Source\Services\Erp\ContractPortfolio;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0246: carteira de contratos com os contratos do cadastro; somente leitura, sem dado do contrato e casando com o 0245. */
final class ContractPortfolioTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->supplier = $this->registry->save($this->admin, 'suppliers', ['legal_name' => 'Portaria Teste Ltda', 'document' => '11222333000181',
            'category' => 'portaria'])['id'];
    }

    public function testPortfolioFromTheRegistry(): void
    {
        $this->contract('Portaria 24h', -400, -10, '1.000,00', 'ipca');
        $this->contract('Limpeza', -335, 400, '500,00', 'igpm');   // aniversário em cerca de 30 dias
        $this->contract('Jardim', -100, null, '200,00', 'nenhum');
        $this->contract('Elevador', -400, 20, '300,00', 'ipca');   // aniversário já passou este ano
        $old = $this->contract('Antigo', -800, -400, '50,00', 'ipca');
        $this->pdo->prepare("UPDATE erp_contracts SET status = 'ended' WHERE id = ?")->execute([$old]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new ContractPortfolio($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([1, 1, 0, 1, 1, 0], array_column($report['rows'], 'contracts'));
        self::assertSame([100000, 30000, 0, 50000, 20000, 0], array_column($report['rows'], 'monthly_cents'));
        self::assertSame(['contracts' => 4, 'monthly_cents' => 200000, 'by_readjustment' => ['ipca' => 130000, 'igpm' => 50000, 'nenhum' => 20000],
            'without_document' => 4], $report['totals']);
        self::assertSame([1, ['contracts' => 1, 'monthly_cents' => 50000], 1, 0], [$report['closable'], $report['readjustment_due'], $report['ended'],
            $report['cancelled']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Portaria', 'Limpeza', 'Teste Ltda'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $preview = (new ContractClosing($this->pdo))->close($this->admin, ['condominium_id' => $this->condominium, 'reason' => 'Conferência', 'dry_run' => true]);
        self::assertSame($report['closable'], $preview['count'], 'closable casa com a prévia do encerramento em lote.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new ContractPortfolio($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function contract(string $object, int $startDays, ?int $endDays, string $monthly, string $readjustment): int
    {
        return (int)$this->registry->save($this->admin, 'contracts', ['condominium_id' => $this->condominium, 'supplier_id' => $this->supplier, 'object' => $object,
            'starts_on' => (new \DateTimeImmutable("{$startDays} days"))->format('Y-m-d'),
            'ends_on' => $endDays !== null ? (new \DateTimeImmutable("{$endDays} days"))->format('Y-m-d') : null, 'monthly_cents' => $monthly,
            'readjustment' => $readjustment])['id'];
    }
}
