<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryCopy;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Copiar lançamentos selecionados para outra competência (ADR 0313, modelo 7). */
final class EntryCopyTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $user;
    private RegistryService $service;
    private int $water;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->user = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->pdo->exec("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('2.07', 'Água', 'despesa', 'analitica')");
        $this->water = (int)$this->pdo->lastInsertId();
    }

    public function testCopiesOpenWithShiftedDueDateAllOrNothing(): void
    {
        $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number) VALUES (?, 'Conta', '077', '0001', '1')")->execute([$this->alpha]);
        $bank = (int)$this->pdo->lastInsertId();
        $paid = $this->entry(['description' => 'Copasa', 'amount_cents' => '1.713,08', 'competence' => '2026-01', 'due_on' => '2026-01-31', 'paid_on' => '2026-01-30',
            'bank_account_id' => (string)$bank, 'document_number' => 'NF 9']);
        $open = $this->entry(['description' => 'Limpeza', 'amount_cents' => '350,00', 'competence' => '2026-01', 'due_on' => '2026-01-10']);

        $result = (new EntryCopy($this->pdo))->copy($this->user, ['condominium_id' => $this->alpha, 'entry_ids' => [$paid, (string)$open], 'competence' => '2026-02']);
        self::assertSame([2, 206308], [$result['count'], $result['total_cents']]);
        $copy = $this->pdo->query('SELECT description, amount_cents, competence, due_on, paid_on, bank_account_id, document_number, nature FROM erp_entries WHERE id = '
            . (int)array_column($result['entries'], 'id', 'from')[$paid])->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['Copasa', 171308, '2026-02', '2026-02-28', null, null, 'NF 9', 'despesa'],
            [$copy['description'], (int)$copy['amount_cents'], $copy['competence'], $copy['due_on'], $copy['paid_on'], $copy['bank_account_id'], $copy['document_number'], $copy['nature']]);
        self::assertSame('2026-03-10', EntryCopy::dueOn('2026-01-10', '2026-01', '2026-03'));
        self::assertSame('2026-02-28', EntryCopy::dueOn('2026-03-31', '2026-03', '2026-02'), 'Para trás também, no último dia do mês.');

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn();
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-05', 'closed')")->execute([$this->alpha]);
        try {
            (new EntryCopy($this->pdo))->copy($this->user, ['condominium_id' => $this->alpha, 'entry_ids' => [$paid, $open], 'competence' => '2026-05']);
            self::fail('Copiou para mês fechado.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('_entry', $exception->errors);
        }
        foreach ([['entry_ids' => [], 'competence' => '2026-04'], ['entry_ids' => [$paid], 'competence' => '2026-13']] as $bad) {
            try {
                (new EntryCopy($this->pdo))->copy($this->user, $bad + ['condominium_id' => $this->alpha]);
                self::fail('Aceitou: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame($count, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'Recusa não grava nada.');

        $outsider = $this->user([$this->beta]);
        $this->expectException(CondominiumAccessDenied::class);
        (new EntryCopy($this->pdo))->copy($outsider, ['condominium_id' => $this->alpha, 'entry_ids' => [$paid], 'competence' => '2026-04']);
    }

    private function entry(array $over): int
    {
        return (int)$this->service->save($this->user, 'entries', $over + ['condominium_id' => (string)$this->alpha, 'nature' => 'despesa', 'chart_account_id' => (string)$this->water, 'status' => 'active'])['id'];
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
