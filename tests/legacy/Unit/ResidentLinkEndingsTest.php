<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ResidentLinkEndings;

/**
 * ADR 0242: vínculos perto do fim ou vencidos, por faixa. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a
 * regra pura das faixas e dos limites, o resumo (total = soma das linhas), a consulta sem dado pessoal, a validação, o
 * escopo e o contrato de reuso; os vínculos gravados pelo cadastro são conferidos no MariaDB
 * (Integration\ResidentLinkEndingsTest).
 */
final class ResidentLinkEndingsTest extends TestCase
{
    private PDO $pdo;
    private ResidentLinkEndings $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, relationship TEXT, name TEXT, document TEXT,
                email TEXT, phone TEXT, starts_on TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new ResidentLinkEndings($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{0:?int, 1:string, 2:?string}> unidade, vínculo e fim, com a data de referência 2026-11-11 */
    private static function links(): array
    {
        return [
            [11, 'owner', '2026-07-01'], [11, 'tenant', '2026-08-12'],    // há 133 e 91 dias: mais de 90
            [11, 'tenant', '2026-08-13'], [11, 'tenant', '2026-10-11'],   // há 90 e 31 dias
            [11, 'tenant', '2026-10-12'], [12, 'tenant', '2026-11-10'],   // há 30 e 1 dia (unidade inativa)
            [11, 'resident', '2026-11-11'], [11, 'tenant', '2026-12-11'], // hoje (ainda vale) e daqui a 30 dias
            [11, 'financial', '2026-12-12'],                              // 31 dias
            [11, 'tenant', '2027-02-09'], [11, 'tenant', '2027-02-10'],   // 90 dias e depois de 90
            [null, 'owner', null], [11, 'outro', '2026-10-01'],           // sem fim; tipo desconhecido fica de fora
        ];
    }

    public function testBucketsAndSummarizePureRule(): void
    {
        $on = '2026-11-11';
        self::assertSame(['expired_over_90', 'expired_31_90', 'expired_31_90', 'expired_1_30', 'expired_1_30', 'ending_0_30', 'ending_0_30', 'ending_31_60',
            'ending_61_90', 'later', null], array_map(static fn (?string $d): ?string => ResidentLinkEndings::bucket($d, $on),
            ['2026-08-12', '2026-08-13', '2026-10-11', '2026-10-12', '2026-11-10', '2026-11-11', '2026-12-11', '2026-12-12', '2027-02-09', '2027-02-10', null]));
        self::assertSame('expired_1_30', ResidentLinkEndings::bucket('2026-11-10 00:00:00', $on));

        $summary = ResidentLinkEndings::summarize(array_map(static fn (array $l): array => ['relationship' => $l[1], 'ends_on' => $l[2], 'unit_active' => $l[0] !== 12],
            self::links()), $on);
        self::assertSame(array_keys(ResidentLinkEndings::BUCKETS), array_column($summary['rows'], 'bucket'));
        self::assertSame([2, 2, 2, 2, 1, 1], array_column($summary['rows'], 'links'));
        self::assertSame([0, 0, 1, 0, 0, 0], array_column($summary['rows'], 'inactive_unit'));
        self::assertSame(['owner' => 1, 'tenant' => 1, 'resident' => 0, 'financial' => 0], $summary['rows'][0]['by_relationship']);
        self::assertSame(['links' => 10, 'by_relationship' => ['owner' => 1, 'tenant' => 7, 'resident' => 1, 'financial' => 1], 'inactive_unit' => 1], $summary['totals']);
        self::assertSame($summary['totals']['links'], array_sum(array_column($summary['rows'], 'links')), 'Total = soma das linhas.');
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['links'], array_sum($row['by_relationship']));
        }
        self::assertSame([5, 1, 1], [$summary['closable'], $summary['open_ended'], $summary['later']], 'Vencidos com unidade ativa: o que o 0241 encerraria.');
        $empty = ResidentLinkEndings::summarize([], $on);
        self::assertSame([6, 0, 0], [count($empty['rows']), $empty['totals']['links'], $empty['closable']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (11, 1, 'Apto 101', 'active', NULL), (12, 1, 'Apto 102', 'inactive', NULL),
            (13, 1, 'Apto 103', 'active', '2026-01-01 00:00:00'), (14, 3, 'Fora', 'active', NULL)");
        $link = $this->pdo->prepare('INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email, phone, starts_on, ends_on, status,
            deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::links() as [$unit, $relationship, $ends]) {
            $link->execute([1, $unit, 'Apto x', $relationship, 'Fulano de Tal', '12345678909', 'fulano@exemplo.com', '11988887777', null, $ends, 'active', null]);
        }
        $link->execute([1, 13, 'Apto 103', 'tenant', 'Unidade excluída', null, null, null, null, '2026-11-01', 'active', null]);   // conta como unidade inativa
        $link->execute([1, 11, 'Apto 101', 'tenant', 'Encerrado', null, null, null, null, '2026-11-01', 'inactive', null]);
        $link->execute([1, 11, 'Apto 101', 'tenant', 'Excluído', null, null, null, null, '2026-11-01', 'active', '2026-11-02 00:00:00']);
        $link->execute([3, 14, 'Fora', 'tenant', 'Outro', null, null, null, null, '2026-11-01', 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-11-11');
        self::assertSame([1, '2026-11-11', [2, 2, 3, 2, 1, 1], 11, 2, 5, 1, 1], [$report['condominium_id'], $report['on'], array_column($report['rows'], 'links'),
            $report['totals']['links'], $report['totals']['inactive_unit'], $report['closable'], $report['open_ended'], $report['later']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', '12345678909', 'exemplo.com', '11988887777', 'Apto', 'unit_id', 'ends_on', '2026-10'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal nem data individual: ' . $needle);
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/ResidentLinkEndings.php');
        foreach (["RegistryService::definition('residents')", '$this->scope->assertAccess(', "deleted_at IS NULL AND status = 'active'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'name', 'document', 'email', 'phone', 'label FROM', 'unit_label', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ResidentLinkEndings.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.residents.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/resident-link-endings", "ResidentLinkEndings:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
