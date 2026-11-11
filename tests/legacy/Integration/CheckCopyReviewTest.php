<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CheckCopyReview;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0214: conferência das cópias de cheques com os lançamentos e a sequência do talão; somente leitura. */
final class CheckCopyReviewTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $bank;
    private int $chartAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->chartAccount = $this->registry->save($this->admin, 'chart', ['condominium_id' => $this->condominium, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Manutenção'])['id'];
    }

    public function testChecksAgainstEntriesAndSequence(): void
    {
        $paid = $this->expense('900,00', '2026-11-10');
        $open = $this->expense('120,00', null);
        $cancelled = $this->expense('80,00', null);
        $ok = $this->check(2001, '900,00', $paid);
        $wrong = $this->check(2002, '950,00', $paid);
        $unpaid = $this->check(2005, '120,00', $open);
        $loose = $this->check(2006, '40,00', null);
        $gone = $this->check(2007, '80,00', $cancelled);
        $this->pdo->exec("UPDATE erp_entries SET status = 'inactive' WHERE id = {$cancelled}");   // cancelado depois do cheque (EntryCancellation)
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new CheckCopyReview($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame([5, 2, [2003, 2004]], [$report['checks'], $report['missing_numbers'], $report['accounts'][0]['missing']]);
        self::assertSame([[$wrong, ['amount_mismatch']], [$unpaid, ['entry_unpaid']], [$loose, ['no_entry']], [$gone, ['entry_cancelled']]],
            array_map(static fn (array $i): array => [$i['check_copy_id'], $i['issues']], $report['items']));
        self::assertNotContains($ok, array_column($report['items'], 'check_copy_id'));
        self::assertStringNotContainsString('Favorecido Pessoa', (string)json_encode($report), 'Sem favorecido.');
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CheckCopyReview($this->pdo))->build($limited, $this->condominium);
            self::fail('Conferiu condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function expense(string $amount, ?string $paidOn): int
    {
        return $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'chart_account_id' => $this->chartAccount,
            'amount_cents' => $amount, 'competence' => '2026-11', 'description' => 'Serviço', 'due_on' => '2026-11-10']
            + ($paidOn !== null ? ['paid_on' => $paidOn, 'bank_account_id' => $this->bank] : []))['id'];
    }

    private function check(int $number, string $amount, ?int $entry): int
    {
        $this->pdo->prepare("INSERT INTO erp_documents (condominium_id, folder, title, original_name, path, mime, size_bytes, sha256)
            VALUES (?, 'outros', 'Cheque', 'c.pdf', ?, 'application/pdf', 10, ?)")
            ->execute([$this->condominium, $this->condominium . '/2026/' . bin2hex(random_bytes(16)) . '.pdf', hash('sha256', random_bytes(8))]);
        $image = (int)$this->pdo->lastInsertId();
        return $this->registry->save($this->admin, 'check_copies', ['condominium_id' => $this->condominium, 'bank_account_id' => $this->bank,
            'check_number' => (string)$number, 'issued_on' => '2026-11-09', 'payee' => 'Favorecido Pessoa', 'amount_cents' => $amount, 'document_id' => $image]
            + ($entry !== null ? ['entry_id' => $entry] : []))['id'];
    }
}
