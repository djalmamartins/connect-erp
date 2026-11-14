<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceTypeDeactivation;
use Source\Services\Erp\InvoiceTypeOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0297: tipos de faturas próprios sem uso inativados em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura (o limite do período e da criação), a validação, o escopo, as
 * recusas (uma por caso, tudo desfeito), a prévia, a inativação só dos tipos próprios sem uso (os com fatura em preparação
 * e os que encobrem um padrão ativo contados à parte), sem tocar padrão nem fatura e sem apagar, a situação da 0296 depois
 * e o contrato. As recorrências vêm do cadastro. O MariaDB confere com tipos de verdade
 * (Integration\InvoiceTypeDeactivationTest).
 */
final class InvoiceTypeDeactivationTest extends TestCase
{
    private PDO $pdo;
    private InvoiceTypeDeactivation $service;

    protected function setUp(): void
    {
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_chart_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, code TEXT, name TEXT, nature TEXT, kind TEXT, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_invoice_types (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, name TEXT, chart_account_id INTEGER, recurrence TEXT,
                default_amount_cents INTEGER NULL, notes TEXT, status TEXT, updated_by INTEGER, created_at TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_invoices (id INTEGER PRIMARY KEY, condominium_id INTEGER, invoice_type_id INTEGER, competence TEXT, status TEXT, amount_cents INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$m, $a] = self::recurrences();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_chart_accounts (id, condominium_id, code, name, nature, kind, status) VALUES (1, NULL, '1.01', 'Conta Secreta', 'receita', 'analitica', 'active')");
        $type = $this->pdo->prepare("INSERT INTO erp_invoice_types (id, condominium_id, name, chart_account_id, recurrence, default_amount_cents, notes, status, updated_by, created_at, deleted_at)
            VALUES (?, ?, ?, 1, ?, 1000, 'Observação reservada', ?, 3, ?, ?)");
        foreach ([[1, 1, 'Obra', $m, 'active', '2023-01-01 10:00:00', null], [2, 1, 'Rateio', $a, 'active', '2023-05-01 10:00:00', null],
            [3, 1, 'Fundo', $m, 'active', '2023-01-01 10:00:00', null], [4, 1, 'Jardim', $m, 'active', '2023-01-01 10:00:00', null],
            [5, 1, 'Taxa', $m, 'active', '2023-01-01 10:00:00', null], [6, 1, 'Nova', $a, 'active', '2024-12-01 10:00:00', null],
            [7, 1, 'Velha', $m, 'inactive', '2023-01-01 10:00:00', null], [8, 1, 'Excluida', $m, 'active', '2023-01-01 10:00:00', '2026-01-01 10:00:00'],
            [9, 3, 'Fora', $m, 'active', '2023-01-01 10:00:00', null], [10, 1, 'Seguro', $a, 'active', '2023-01-01 10:00:00', null],
            [11, 1, 'Limpeza', $m, 'active', '2024-11-30 23:00:00', null], [12, 1, 'Agua', $m, 'active', '2023-01-01 10:00:00', null],
            [20, null, 'Taxa', $m, 'active', '2020-01-01 10:00:00', null], [21, null, 'Agua', $m, 'inactive', '2020-01-01 10:00:00', null],
            [22, null, 'Padrao', $m, 'active', '2020-01-01 10:00:00', null]] as $row) {
            $type->execute($row);
        }
        $invoice = $this->pdo->prepare('INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, status, amount_cents) VALUES (?, ?, ?, ?, 100)');
        foreach ([[1, 2, '2024-11', 'issued'], [1, 3, '2024-12', 'issued'], [1, 4, '2023-06', 'draft'], [1, 10, '2026-01', 'cancelled'], [1, 22, '2020-01', 'issued']] as $row) {
            $invoice->execute($row);
        }
        $this->service = new InvoiceTypeDeactivation($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} as duas primeiras recorrências, vindas do cadastro */
    private static function recurrences(): array
    {
        $recurrences = array_keys(InvoiceTypeOverview::recurrences());
        self::assertGreaterThanOrEqual(2, count($recurrences));
        return [$recurrences[0], $recurrences[1]];
    }

    public function testProblemRule(): void
    {
        $old = ['status' => 'active', 'created_on' => '2023-01-01'];
        self::assertNull(InvoiceTypeDeactivation::problem($old, null, 0, false, '2024-12', 24), 'Nunca teve fatura.');
        self::assertNull(InvoiceTypeDeactivation::problem($old, '2024-11', 0, false, '2024-12', 24), 'Um mês antes do período.');
        self::assertNotNull(InvoiceTypeDeactivation::problem($old, '2024-12', 0, false, '2024-12', 24), 'O primeiro mês do período conta.');
        self::assertNull(InvoiceTypeDeactivation::problem(['created_on' => '2024-11-30'] + $old, null, 0, false, '2024-12', 24), 'Criado um dia antes do período.');
        self::assertNotNull(InvoiceTypeDeactivation::problem(['created_on' => '2024-12-01'] + $old, null, 0, false, '2024-12', 24), 'Criado no primeiro dia do período.');
        self::assertNotNull(InvoiceTypeDeactivation::problem($old, null, 1, false, '2024-12', 24));
        self::assertNotNull(InvoiceTypeDeactivation::problem($old, null, 0, true, '2024-12', 24));
        self::assertNotNull(InvoiceTypeDeactivation::problem(['status' => 'inactive'] + $old, null, 0, false, '2024-12', 24));
        self::assertNotNull(InvoiceTypeDeactivation::problem(null, null, 0, false, '2024-12', 24));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Cobrança encerrada'];
        foreach ([['condominium_id' => null] + $ok, ['condominium_id' => 'abc'] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('a', 256)] + $ok,
            ['months' => (string)(InvoiceTypeDeactivation::MIN_MONTHS - 1)] + $ok, ['months' => (string)(InvoiceTypeDeactivation::MAX_MONTHS + 1)] + $ok, ['months' => 'x'] + $ok,
            ['invoice_type_ids' => []] + $ok, ['invoice_type_ids' => 'x'] + $ok, ['invoice_type_ids' => ['a']] + $ok,
            ['invoice_type_ids' => range(1, InvoiceTypeDeactivation::MAX_TYPES + 1)] + $ok] as $input) {
            try {
                $this->service->deactivate($this->user(), $input, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('active', $this->typeStatus(1), 'Nada mudou.');
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->deactivate($this->user(), ['condominium_id' => 3] + $ok, '2026-11-14');
    }

    public function testDryRunThenDeactivateTheUnusedOnes(): void
    {
        [$m, $a] = self::recurrences();
        $input = ['condominium_id' => '1', 'reason' => 'Cobrança encerrada', 'dry_run' => true];
        $preview = $this->service->deactivate($this->user(), $input, '2026-11-14');
        self::assertSame([[1, 2, 10, 11, 12], 1, 1, 0, true, '2024-12', '2026-11', 24], [array_column($preview['invoice_types'], 'id'), $preview['skipped_draft'],
            $preview['skipped_overriding'], $preview['changed'], $preview['dry_run'], $preview['from'], $preview['to'], $preview['months']],
            'Os com fatura em preparação e os que encobrem um padrão ativo ficam de fora e são contados.');
        self::assertSame([null, '2024-11', null, null, null], array_column($preview['invoice_types'], 'last_competence'), 'A fatura cancelada não conta.');
        self::assertSame('active', $this->typeStatus(1), 'A prévia desfaz.');

        $done = $this->service->deactivate($this->user(), ['dry_run' => false] + $input, '2026-11-14');
        self::assertSame([5, 5], [$done['count'], $done['changed']]);
        $byRecurrence = array_combine(array_column($done['by_recurrence'], 'recurrence'), array_column($done['by_recurrence'], 'invoice_types'));
        self::assertSame([3, 2], [$byRecurrence[$m], $byRecurrence[$a]]);
        self::assertSame($done['count'], array_sum($byRecurrence), 'O total é a soma das recorrências.');
        foreach ([1, 2, 10, 11, 12] as $id) {
            self::assertSame('inactive', $this->typeStatus($id), "Tipo {$id}.");
        }
        foreach ([3, 4, 5, 6, 9, 20, 22] as $id) {
            self::assertSame('active', $this->typeStatus($id), "Tipo {$id}: com fatura, em preparação, encobrindo, novo, de outro condomínio ou padrão ficam.");
        }
        self::assertSame([7, 3], [(int)$this->pdo->query('SELECT updated_by FROM erp_invoice_types WHERE id = 1')->fetchColumn(),
            (int)$this->pdo->query('SELECT updated_by FROM erp_invoice_types WHERE id = 20')->fetchColumn()], 'O padrão não é tocado.');
        self::assertSame([15, 5, 1], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_invoice_types')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM erp_invoices')->fetchColumn(),
            (int)$this->pdo->query("SELECT COUNT(*) FROM erp_invoices WHERE status = 'draft'")->fetchColumn()], 'Nada é apagado e nenhuma fatura muda.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Obra', 'Rateio', 'Seguro', 'Conta Secreta', 'Observação', 'encerrada', 'name', 'notes', 'amount'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $report = (new InvoiceTypeOverview($this->pdo, new CondominiumScope($this->pdo)))->build($this->user(), 1, null, '2026-11-14');
        self::assertSame(6, $report['totals']['inactive_own'], 'Na situação da 0296, os inativados saem dos tipos à mão.');
    }

    public function testRefusalsUndoTheWholeBatch(): void
    {
        foreach ([3 => 'teve fatura', 4 => 'em preparação', 5 => 'substitui um tipo padrão', 6 => 'foi criado', 7 => 'já está inativo', 8 => 'não encontrado',
            9 => 'não encontrado', 20 => 'não encontrado', 999 => 'não encontrado'] as $bad => $text) {
            try {
                $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Cobrança encerrada', 'invoice_type_ids' => [1, $bad]], '2026-11-14');
                self::fail("O tipo {$bad} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$bad, $exception->errors['_invoice_type']);
                self::assertStringContainsString($text, $exception->errors['_form']);
            }
            self::assertSame('active', $this->typeStatus(1), 'Tudo ou nada.');
        }
        $done = $this->service->deactivate($this->user(), ['condominium_id' => 1, 'reason' => 'Cobrança encerrada', 'invoice_type_ids' => ['2'], 'months' => '6'], '2026-11-14');
        self::assertSame([[2], 'active'], [array_column($done['invoice_types'], 'id'), $this->typeStatus(1)], 'Com ids, só os informados.');
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/InvoiceTypeDeactivation.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('invoice_types')", "\$definition['override']", 'InvoiceTypeOverview::recurrences()',
            'DiscountOverview::months(', 'SELECT NOW()', 'FOR UPDATE', 'WHERE t.condominium_id = ? AND t.deleted_at IS NULL', "status <> 'cancelled'",
            '$dryRun ? $this->pdo->rollBack() : $this->pdo->commit()', 'if (!$dryRun)', 'Audit::record(', "AND status = 'active'", 'rowCount() !== 1'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['DELETE ', 'deleted_at =', 'UPDATE erp_invoices', 'INSERT ', 't.name', 'notes', 'amount', 'chart_account'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Sem apagar, sem mexer em fatura e sem nome, valor ou conta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/InvoiceTypeDeactivation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can(\$types . '.view', \$user)", "Access::can(\$types . '.manage', \$user)",
            "Access::can('erp.invoices.view', \$user)", 'catch (CondominiumAccessDenied)', 'catch (DomainException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/invoice-types/deactivate-unused", "InvoiceTypeDeactivation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/invoice-types"'), strpos($index, $route), 'Logo depois do relatório.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/invoice-types:\n"), strpos($openapi, "  /invoice-types/deactivate-unused:\n"));
    }

    private function typeStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_invoice_types WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
