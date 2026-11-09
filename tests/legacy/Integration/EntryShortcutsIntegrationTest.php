<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BankBalances;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitDocuments;

/** Lançamentos avulsos e recibos (ADR 0057): desconto, multa, cobrança extra, transferência, recibo, fechamento e escopo. */
final class EntryShortcutsIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private EntryShortcuts $shortcuts;
    private User $admin;
    private int $fee;
    private int $type;
    private int $bankA;
    private int $bankB;
    private string $due;
    private string $competence;
    /** @var list<int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->shortcuts = new EntryShortcuts($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa condominial'])['id'];
        $this->type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $this->fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        foreach ([['101', '0,250000'], ['102', '0,250000'], ['201', '0,500000']] as [$number, $fraction]) {
            $this->units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento', 'ideal_fraction' => $fraction])['id'];
        }
        $this->bankA = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->bankB = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '9999-0', 'kind' => 'poupanca'])['id'];
        $this->due = date('Y-m-d', strtotime('+10 days'));
        $this->competence = substr($this->due, 0, 7);
    }

    public function testDiscountReducesOpenReceivableWithTrail(): void
    {
        $entry = $this->invoiceEntry();
        $result = $this->shortcuts->discount($this->admin, $entry, '50,00', 'Acordo de vizinhança aprovado em assembleia');
        self::assertSame([50000, 5000, 45000], [$result['before_cents'], $result['discount_cents'], $result['after_cents']]);
        self::assertSame(45000, (int)$this->pdo->query("SELECT amount_cents FROM erp_entries WHERE id = {$entry}")->fetchColumn());
        self::assertCount(1, $result['adjustments']);

        foreach ([['500,00', 'Não zera a conta'], ['0', 'Sem valor'], ['10,00', '']] as [$amount, $reason]) {
            try {
                $this->shortcuts->discount($this->admin, $entry, $amount, $reason === '' ? 'abc' : $reason);
                self::fail("Aceitou desconto inválido: {$amount}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->pdo->exec("UPDATE erp_entries SET paid_on = CURDATE(), bank_account_id = {$this->bankA} WHERE id = {$entry}");
        $this->expectException(DomainException::class);
        $this->shortcuts->discount($this->admin, $entry, '1,00', 'Conta já paga não aceita desconto');
    }

    public function testExtraChargeSplitsLocksAndCancels(): void
    {
        $charge = $this->shortcuts->createCharge($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'extra', 'description' => 'Pintura da fachada',
            'chart_account_id' => $this->fee, 'competence' => $this->competence, 'due_on' => $this->due, 'method' => 'fraction', 'amount_cents' => '1.000,00',
            'unit_ids' => $this->units]);
        self::assertSame([100000, 3], [$charge['total_cents'], $charge['units_count']]);
        self::assertEqualsCanonicalizing([25000, 25000, 50000], array_column($charge['entries'], 'amount_cents'));

        // Conta gerada fica travada no cadastro.
        $entryId = $charge['entries'][0]['entry_id'];
        $row = $this->service->find($this->admin, 'receivables', $entryId);
        try {
            $this->service->save($this->admin, 'receivables', ['amount_cents' => '1,00'] + $this->editable($row), $entryId);
            self::fail('Alterou conta gerada por cobrança extra.');
        } catch (RegistryValidationException) {
            self::addToAssertionCount(1);
        }

        $cancelled = $this->shortcuts->cancelCharge($this->admin, $charge['id'], 'Obra adiada pelo conselho');
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(['inactive'], array_values(array_unique(array_column($cancelled['entries'], 'status'))));
    }

    public function testFineIsOneUnitWithReasonAndRespectsClosing(): void
    {
        $input = ['condominium_id' => $this->alpha, 'kind' => 'fine', 'description' => 'Multa por barulho', 'chart_account_id' => $this->fee,
            'competence' => $this->competence, 'due_on' => $this->due, 'amount_cents' => '150,00', 'unit_ids' => [$this->units[0]],
            'reason' => 'Art. 12 do regulamento interno, ocorrência 45'];
        foreach ([['unit_ids' => $this->units], ['reason' => '']] as $bad) {
            try {
                $this->shortcuts->createCharge($this->admin, $bad + $input);
                self::fail('Aceitou multa inválida.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $fine = $this->shortcuts->createCharge($this->admin, $input);
        self::assertSame(['fine', 'fixed', 15000], [$fine['kind'], $fine['method'], $fine['total_cents']]);

        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, $this->competence);
        $this->expectException(DomainException::class);
        $this->shortcuts->createCharge($this->admin, $input);
    }

    public function testTransferMovesBalanceAndKeepsReconciliation(): void
    {
        $today = date('Y-m-d');
        $transfer = $this->shortcuts->transfer($this->admin, ['condominium_id' => $this->alpha, 'from_bank_account_id' => $this->bankA,
            'to_bank_account_id' => $this->bankB, 'amount_cents' => '300,00', 'transferred_on' => $today, 'description' => 'Reserva do mês']);
        $balances = array_column((new BankBalances($this->pdo))->forUser($this->admin, $this->alpha), 'balance_cents', 'id');
        self::assertSame([-30000, 30000], [$balances[$this->bankA], $balances[$this->bankB]]);

        $statement = (new FinancialReports($this->pdo))->statement($this->admin, $this->alpha, $today, $today);
        self::assertTrue($statement['totals']['reconciled'], 'Transferência soma zero no condomínio.');

        $this->shortcuts->cancelTransfer($this->admin, $transfer['id'], 'Lançada na conta errada');
        $balances = array_column((new BankBalances($this->pdo))->forUser($this->admin, $this->alpha), 'balance_cents', 'id');
        self::assertSame([0, 0], [$balances[$this->bankA], $balances[$this->bankB]]);

        $this->expectException(InvalidArgumentException::class);
        $this->shortcuts->transfer($this->admin, ['condominium_id' => $this->alpha, 'from_bank_account_id' => $this->bankA,
            'to_bank_account_id' => $this->bankA, 'amount_cents' => '1,00', 'transferred_on' => $today, 'description' => 'Mesma conta']);
    }

    public function testReceiptOnlyForPaidEntriesOncePerEntry(): void
    {
        $entry = $this->invoiceEntry();
        $documents = new UnitDocuments($this->pdo);
        $input = ['kind' => 'receipt', 'unit_id' => $this->units[0], 'entry_ids' => [$entry]];
        try {
            $documents->issue($this->admin, $input);
            self::fail('Emitiu recibo de conta em aberto.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $this->pdo->exec("UPDATE erp_entries SET paid_on = CURDATE(), bank_account_id = {$this->bankA} WHERE id = {$entry}");
        $receipt = $documents->issue($this->admin, $input);
        self::assertSame(['receipt', 50000], [$receipt['kind'], $receipt['total_cents']]);
        self::assertNotEmpty($receipt['verification_code']);
        try {
            $documents->issue($this->admin, $input);
            self::fail('Emitiu dois recibos válidos para a mesma conta.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $documents->cancel($this->admin, $receipt['id'], 'Recibo emitido com erro');
        self::assertSame('receipt', $documents->issue($this->admin, $input)['kind'], 'Cancelado, libera a conta.');
    }

    public function testPaymentWithValidReceiptCannotBeUndone(): void
    {
        $entry = $this->invoiceEntry();
        $this->pdo->exec("UPDATE erp_entries SET paid_on = CURDATE(), bank_account_id = {$this->bankA} WHERE id = {$entry}");
        $documents = new UnitDocuments($this->pdo);
        $receipt = $documents->issue($this->admin, ['kind' => 'receipt', 'unit_id' => $this->units[0], 'entry_ids' => [$entry]]);

        $row = $this->pdo->query("SELECT * FROM erp_entries WHERE id = {$entry}")->fetch(\PDO::FETCH_ASSOC);
        $undo = ['paid_on' => '', 'bank_account_id' => ''] + $this->editable($row);
        try {
            $this->service->save($this->admin, 'entries', $undo, $entry);
            self::fail('Estornou a baixa de conta com recibo válido.');
        } catch (RegistryValidationException $error) {
            self::assertStringContainsString('Cancele o recibo', (string)($error->errors['paid_on'] ?? ''));
        }
        self::assertNotNull($this->pdo->query("SELECT paid_on FROM erp_entries WHERE id = {$entry}")->fetchColumn(), 'Baixa continua.');

        $documents->cancel($this->admin, $receipt['id'], 'Pagamento devolvido pelo banco');
        $this->service->save($this->admin, 'entries', $undo, $entry);
        self::assertNull($this->pdo->query("SELECT paid_on FROM erp_entries WHERE id = {$entry}")->fetchColumn(), 'Sem recibo válido, o estorno passa.');
    }

    public function testReceiptTreatsOtherCondominiumEntryAsMissing(): void
    {
        $betaUnit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->beta, 'number' => '901', 'type' => 'apartamento'])['id'];
        $betaEntry = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->beta, 'nature' => 'receita', 'chart_account_id' => $this->fee,
            'amount_cents' => '10,00', 'competence' => $this->competence, 'description' => 'Taxa Beta', 'due_on' => $this->due, 'unit_id' => $betaUnit])['id'];
        $this->pdo->exec("UPDATE erp_entries SET paid_on = CURDATE() WHERE id = {$betaEntry}");
        $documents = new UnitDocuments($this->pdo);
        $messages = [];
        foreach ([$betaEntry, 99999999] as $id) {
            try {
                $documents->issue($this->admin, ['kind' => 'receipt', 'unit_id' => $this->units[0], 'entry_ids' => [$id]]);
                self::fail('Emitiu recibo com conta que não é do condomínio da unidade.');
            } catch (InvalidArgumentException $error) {
                $messages[] = $error->getMessage();
            }
        }
        self::assertSame(['Alguma conta escolhida não existe.', 'Alguma conta escolhida não existe.'], $messages,
            'Id de outro condomínio responde igual a id inexistente (ADR 0062).');
    }

    public function testScope(): void
    {
        $outsider = $this->user([$this->beta]);
        $entry = $this->invoiceEntry();
        $this->expectException(RegistryNotFound::class);
        $this->shortcuts->discount($outsider, $entry, '1,00', 'Fora do escopo do usuário');
    }

    private function invoiceEntry(): int
    {
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $this->type,
            'competence' => $this->competence, 'due_on' => $this->due])['id']);
        foreach ($invoice['items'] as $item) {
            if ((int)$item['unit_id'] === $this->units[0]) {
                return (int)$item['entry_id'];
            }
        }
        return (int)$invoice['items'][0]['entry_id'];
    }

    private function editable(array $entry): array
    {
        $fields = ['condominium_id', 'nature', 'competence', 'description', 'chart_account_id', 'due_on', 'unit_id', 'document_number', 'status'];
        $values = array_intersect_key($entry, array_flip($fields));
        $values['amount_cents'] = number_format(((int)$entry['amount_cents']) / 100, 2, ',', '');
        return $values;
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
