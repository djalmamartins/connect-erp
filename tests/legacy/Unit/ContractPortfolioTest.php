<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ContractPortfolio;

/**
 * ADR 0246: carteira de contratos numa data. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a regra pura
 * das faixas, dos aniversários e do resumo (total = soma das linhas), a consulta com o documento e o fornecedor, sem dado do
 * contrato, a validação, o escopo e o contrato; os contratos gravados pelo cadastro são conferidos no MariaDB
 * (Integration\ContractPortfolioTest).
 */
final class ContractPortfolioTest extends TestCase
{
    private PDO $pdo;
    private ContractPortfolio $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_suppliers (id INTEGER PRIMARY KEY, legal_name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE erp_contracts (id INTEGER PRIMARY KEY, condominium_id INTEGER, supplier_id INTEGER, object TEXT, starts_on TEXT, ends_on TEXT,
                monthly_cents INTEGER, readjustment TEXT, document_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new ContractPortfolio($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{0:string, 1:?string, 2:int, 3:string, 4:bool, 5:bool}> início, fim, valor, índice, documento e vínculos, com on = 2026-11-11 */
    private static function contracts(): array
    {
        return [
            ['2025-01-01', '2026-11-10', 100, 'ipca', true, true],     // vencido: o 0245 encerra
            ['2025-01-01', '2026-10-01', 200, 'igpm', false, false],   // vencido, fornecedor inativo: o 0245 não regrava
            ['2025-11-11', '2026-11-11', 300, 'ipca', true, true],     // termina hoje; aniversário hoje
            ['2025-12-01', '2027-11-30', 400, 'igpm', true, true],     // aniversário em 20 dias
            ['2026-03-01', '2026-12-12', 500, 'ipca', true, true],     // 31 dias; aniversário depois do fim
            ['2024-02-29', null, 600, 'ipca', false, true],            // indeterminado; aniversário 28/02 em 109 dias
            ['2025-01-15', null, 700, 'nenhum', true, true],           // sem reajuste
            ['2027-01-01', '2028-01-01', 800, 'ipca', true, true],     // ainda não começou
            ['2023-02-09', null, 900, 'igpm', true, true],             // aniversário em 90 dias: entra
        ];
    }

    public function testBucketsAnniversariesAndSummarizePureRule(): void
    {
        $on = '2026-11-11';
        self::assertSame(['expired', 'expired', 'ending_0_30', 'ending_over_90', 'ending_31_90', 'open_ended', 'open_ended', 'not_started', 'open_ended'],
            array_map(static fn (array $c): string => ContractPortfolio::bucket($c[0], $c[1], $on), self::contracts()));
        self::assertSame(['2026-11-11', '2026-12-01', '2027-02-28', '2027-02-09', '2027-11-11', '2028-02-29'], [
            ContractPortfolio::nextAnniversary('2025-11-11', $on), ContractPortfolio::nextAnniversary('2025-12-01', $on),
            ContractPortfolio::nextAnniversary('2024-02-29', $on), ContractPortfolio::nextAnniversary('2023-02-09', $on),
            ContractPortfolio::nextAnniversary('2026-11-11', $on), ContractPortfolio::nextAnniversary('2024-02-29', '2028-01-01')]);

        $summary = ContractPortfolio::summarize(array_map(static fn (array $c): array => ['starts_on' => $c[0], 'ends_on' => $c[1], 'monthly_cents' => $c[2],
            'readjustment' => $c[3], 'has_document' => $c[4], 'links_active' => $c[5]], self::contracts()), $on, 4, 2);
        self::assertSame(array_keys(ContractPortfolio::BUCKETS), array_column($summary['rows'], 'bucket'));
        self::assertSame([2, 1, 1, 1, 3, 1], array_column($summary['rows'], 'contracts'));
        self::assertSame([300, 300, 500, 400, 2200, 800], array_column($summary['rows'], 'monthly_cents'));
        self::assertSame(['ipca' => 600, 'igpm' => 900, 'nenhum' => 700], $summary['rows'][4]['by_readjustment']);
        self::assertSame(['contracts' => 9, 'monthly_cents' => 4500, 'by_readjustment' => ['ipca' => 2300, 'igpm' => 1500, 'nenhum' => 700], 'without_document' => 2],
            $summary['totals']);
        self::assertSame($summary['totals']['contracts'], array_sum(array_column($summary['rows'], 'contracts')), 'Total = soma das linhas.');
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['monthly_cents'], array_sum($row['by_readjustment']));
        }
        self::assertSame([1, ['contracts' => 3, 'monthly_cents' => 1600], 4, 2], [$summary['closable'], $summary['readjustment_due'], $summary['ended'],
            $summary['cancelled']]);
        $empty = ContractPortfolio::summarize([], $on);
        self::assertSame([6, 0, 0], [count($empty['rows']), $empty['totals']['contracts'], $empty['readjustment_due']['contracts']]);
    }

    public function testBuildWithoutContractData(): void
    {
        $this->pdo->exec("INSERT INTO erp_suppliers (id, legal_name, status, deleted_at) VALUES (21, 'Portaria Ltda', 'active', NULL), (22, 'Antiga Ltda', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_documents (id, condominium_id, deleted_at) VALUES (51, 1, NULL), (52, 1, '2026-10-01 00:00:00'), (53, 3, NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_contracts (condominium_id, supplier_id, object, starts_on, ends_on, monthly_cents, readjustment, document_id, notes,
            status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 21, 'Portaria 24h', '2025-01-01', '2026-10-31', 1000000, 'ipca', 51, 'Nota interna', 'active', null]);   // vencido: encerrável
        $insert->execute([1, 22, 'Piscina', '2025-01-01', '2026-10-31', 200000, 'igpm', null, null, 'active', null]);                 // fornecedor inativo
        $insert->execute([1, 21, 'Câmeras', '2025-01-01', '2026-10-31', 300000, 'ipca', 52, null, 'active', null]);                   // documento removido
        $insert->execute([1, 21, 'Elevador', '2025-12-01', null, 400000, 'ipca', 53, null, 'active', null]);                         // documento de outro condomínio
        $insert->execute([1, 21, 'Antigo', '2024-01-01', '2025-01-01', 99999, 'ipca', null, null, 'ended', null]);
        $insert->execute([1, 21, 'Cancelado', '2024-01-01', '2025-01-01', 99999, 'ipca', null, null, 'inactive', null]);
        $insert->execute([1, 21, 'Excluído', '2024-01-01', '2027-01-01', 99999, 'ipca', null, null, 'active', '2026-01-01 00:00:00']);
        $insert->execute([3, 21, 'Fora', '2024-01-01', '2027-01-01', 99999, 'ipca', null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-11-11');
        self::assertSame([1, '2026-11-11', [3, 0, 0, 0, 1, 0], 1900000, 3, 1, ['contracts' => 1, 'monthly_cents' => 400000], 1, 1], [$report['condominium_id'],
            $report['on'], array_column($report['rows'], 'contracts'), $report['totals']['monthly_cents'], $report['totals']['without_document'], $report['closable'],
            $report['readjustment_due'], $report['ended'], $report['cancelled']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Portaria', 'Piscina', 'Ltda', 'Nota interna', 'supplier', 'object', 'document_id'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do contrato: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null], ['x', null], [0, null], [1, '11/11/2026'], [1, '2026-02-30']] as [$c, $on]) {
            try {
                $this->service->build($this->user(), $c, $on);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $on]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ContractPortfolio.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('contracts')", "c.deleted_at IS NULL AND c.status = 'active'",
            'd.condominium_id = c.condominium_id AND d.deleted_at IS NULL', "s.deleted_at IS NULL AND s.status = 'active'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'c.object', 'legal_name', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do contrato: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ContractPortfolio.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.contracts.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/contract-portfolio", "ContractPortfolio:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
