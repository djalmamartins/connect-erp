<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\TaxCoverage;

/** ADR 0262: cobertura das alíquotas de retenção com as alíquotas do cadastro e as padrão; somente leitura e sem nome nem observação. */
final class TaxCoverageTest extends TestCase
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
        $this->pdo->exec("INSERT INTO erp_taxes (condominium_id, kind, name, rate, applies_to) VALUES (NULL, 'iss', 'ISS padrão de teste', 5.00, 'servicos'),
            (NULL, 'irrf', 'IRRF padrão de teste', 1.50, 'servicos')");
        $this->registry->save($this->admin, 'taxes', ['condominium_id' => $this->condominium, 'kind' => 'iss', 'name' => 'ISS do condomínio de teste', 'rate' => '2,00',
            'applies_to' => 'todos', 'notes' => 'Observação de teste']);
        $inss = (int)$this->registry->save($this->admin, 'taxes', ['condominium_id' => $this->condominium, 'kind' => 'inss', 'name' => 'INSS de teste', 'rate' => '11',
            'applies_to' => 'servicos'])['id'];
        $this->pdo->prepare("UPDATE erp_taxes SET status = 'inactive' WHERE id = ?")->execute([$inss]);
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new TaxCoverage($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame(['condominium', 1, 2.0, true, true, true], [$report['rows'][0]['source'], $report['rows'][0]['rates'], $report['rows'][0]['max_rate'],
            $report['rows'][0]['covers_materials'], $report['rows'][0]['overrides_default'], $report['rows'][2]['missing']]);
        self::assertSame([['own' => 1, 'default' => 2, 'inactive_own' => 1, 'rates' => 2], ['inss', 'pis_cofins_csll', 'outro']], [$report['totals'], $report['missing_kinds']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['de teste', 'Observação'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new TaxCoverage($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
