<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\TariffCoverage;

/** ADR 0258: cobertura das tarifas com as tarifas e os relógios do cadastro; somente leitura e sem dado do relógio. */
final class TariffCoverageTest extends TestCase
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
        $unit = (int)$this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
        foreach ([['agua', 'H-101'], ['gas', 'G-101']] as [$kind, $number]) {
            $this->registry->save($this->admin, 'meters', ['condominium_id' => $this->condominium, 'unit_id' => $unit, 'kind' => $kind, 'number' => $number]);
        }
        // Padrão da Connect para a água; o condomínio tem a sua, com o reajuste já cadastrado.
        $this->pdo->exec("INSERT INTO erp_tariffs (condominium_id, kind, name, from_amount, to_amount, price_cents, valid_from) VALUES (NULL, 'agua', 'Faixa padrão', 0, NULL, 500, '2026-01-01')");
        foreach ([['0', '10', '2026-06-01'], ['10', '', '2026-06-01'], ['0', '', '2099-01-01']] as [$from, $to, $validFrom]) {
            $this->registry->save($this->admin, 'tariffs', ['condominium_id' => $this->condominium, 'kind' => 'agua', 'name' => 'Faixa de teste', 'from_amount' => $from,
                'to_amount' => $to, 'price_cents' => '5,00', 'valid_from' => $validFrom]);
        }
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new TariffCoverage($this->pdo))->build($this->admin, $this->condominium, '2026-11-12');
        self::assertSame(['condominium', '2026-06-01', 2, true, true, 0, '2099-01-01'], [$report['rows'][0]['source'], $report['rows'][0]['valid_from'],
            $report['rows'][0]['bands'], $report['rows'][0]['starts_at_zero'], $report['rows'][0]['open_ended'], $report['rows'][0]['gaps'],
            $report['rows'][0]['next_valid_from']]);
        self::assertSame([['meters' => 2, 'bands' => 2], ['gas']], [$report['totals'], $report['missing_kinds']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['H-101', 'G-101', 'Faixa de teste', 'Faixa padrão'] as $needle) {
            self::assertStringNotContainsString($needle, $json, $needle);
        }
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new TariffCoverage($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
