<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InvoiceTypeDeactivation;
use Source\Services\Erp\InvoiceTypeOverview;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0297: tipos de faturas próprios sem uso inativados em lote no MariaDB, com o mês do banco, as travas de linha e a auditoria só quando grava; padrão e faturas intactos. */
final class InvoiceTypeDeactivationTest extends TestCase
{
    private int $condominium;
    private int $account;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (NULL, '1.94', 'Conta Reservada', 'receita', 'analitica')");
        $this->account = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testDeactivatesOnlyTheUnusedOwnTypes(): void
    {
        $recurrence = array_keys(InvoiceTypeOverview::recurrences())[0];
        $unused = $this->type($this->condominium, 'Obra Reservada', $recurrence);
        $used = $this->type($this->condominium, 'Fundo Reservado', $recurrence);
        $draft = $this->type($this->condominium, 'Jardim Reservado', $recurrence);
        $standard = $this->type(null, 'Padrão Reservado', $recurrence);
        $this->invoice($used, 2, 'issued');
        $this->invoice($draft, 30, 'draft');
        $invoices = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_invoices')->fetchColumn();
        $before = $this->audits();

        $input = ['condominium_id' => $this->condominium, 'reason' => 'Cobrança encerrada'];
        $preview = (new InvoiceTypeDeactivation($this->pdo))->deactivate($this->admin, ['dry_run' => true] + $input);
        self::assertSame([[$unused], 1, 0], [array_column($preview['invoice_types'], 'id'), $preview['skipped_draft'], $preview['changed']]);
        self::assertSame((string)$this->pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn(), $preview['to'], 'O mês do banco.');
        self::assertSame([$before, 'active'], [$this->audits(), $this->typeStatus($unused)], 'A prévia não grava nem audita.');

        try {
            (new InvoiceTypeDeactivation($this->pdo))->deactivate($this->admin, ['invoice_type_ids' => [$unused, $standard]] + $input);
            self::fail('O tipo padrão passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$standard, $exception->errors['_invoice_type']);
        }
        self::assertSame('active', $this->typeStatus($unused), 'Tudo ou nada.');

        $done = (new InvoiceTypeDeactivation($this->pdo))->deactivate($this->admin, $input);
        self::assertSame(1, $done['changed']);
        self::assertSame(['inactive', 'active', 'active', 'active'], [$this->typeStatus($unused), $this->typeStatus($used), $this->typeStatus($draft), $this->typeStatus($standard)]);
        self::assertSame($invoices, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_invoices')->fetchColumn(), 'Nenhuma fatura muda.');
        self::assertSame($before + 1, $this->audits(), 'Um registro por tipo inativado.');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Reservad', 'encerrada'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    private function type(?int $condominium, string $name, string $recurrence): int
    {
        $this->pdo->prepare("INSERT INTO erp_invoice_types (condominium_id, name, chart_account_id, recurrence, status, created_at)
            VALUES (?, ?, ?, ?, 'active', DATE_SUB(NOW(), INTERVAL 5 YEAR))")->execute([$condominium, $name, $this->account, $recurrence]);
        return (int)$this->pdo->lastInsertId();
    }

    private function invoice(int $type, int $monthsAgo, string $state): void
    {
        $this->pdo->prepare("INSERT INTO erp_invoices (condominium_id, invoice_type_id, competence, due_on, status, amount_cents)
            VALUES (?, ?, DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m'), CURDATE(), ?, 100)")->execute([$this->condominium, $type, $monthsAgo, $state]);
    }

    private function typeStatus(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_invoice_types WHERE id = {$id}")->fetchColumn();
    }

    private function audits(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_invoice_types'")->fetchColumn();
    }
}
