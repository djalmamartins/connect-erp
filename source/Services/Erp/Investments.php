<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Aplicação financeira separada da conta corrente (ADR 0170), sem banco real e sem integração.
 *
 * - A conta bancária de tipo 'aplicacao' é ligada a uma conta corrente ativa do mesmo condomínio
 *   (parent_bank_account_id). A ligação é auditada.
 * - Aplicar e resgatar reaproveitam a transferência entre contas que já existe (EntryShortcuts::transfer,
 *   AP-029): aplicar vai da conta corrente para a aplicação; resgatar, da aplicação para a conta corrente.
 *   Não é receita nem despesa, e o saldo total não muda. O estorno é o cancelamento da transferência
 *   (POST /bank-transfers/{id}/cancel), que tira a transferência dos saldos sem apagar nada.
 * - O rendimento é receita e o IR/IOF é despesa, lançados normalmente (erp_entries) com a conta de aplicação
 *   como conta bancária. Entram na DRE e no saldo da aplicação.
 * - positions(): saldo das contas correntes e das aplicações numa data, separado, pela mesma conciliação dos
 *   saldos (AccountingReports::accountBalances).
 *
 * Escopo (ADR-002): fora do escopo = 404.
 */
final class Investments
{
    private PDO $pdo;
    private CondominiumScope $scope;
    private ?EntryShortcuts $shortcuts;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?EntryShortcuts $shortcuts = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->shortcuts = $shortcuts;
    }

    /** Liga (ou desliga, com null) a aplicação à conta corrente do mesmo condomínio. */
    public function link(User $user, int $investmentId, mixed $parentId): array
    {
        $investment = $this->account($user, $investmentId);
        if ($investment['kind'] !== 'aplicacao') {
            throw new InvalidArgumentException('Só uma conta de tipo aplicação pode ser ligada a uma conta corrente.');
        }
        $parent = null;
        if ($parentId !== null && $parentId !== '') {
            $parentId = filter_var($parentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $stmt = $this->pdo->prepare("SELECT id, kind FROM erp_bank_accounts WHERE id = :id AND condominium_id = :c AND deleted_at IS NULL AND status = 'active'");
            $stmt->execute(['id' => $parentId === false ? 0 : (int)$parentId, 'c' => $investment['condominium_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['kind'] !== 'corrente') {
                throw new InvalidArgumentException('Escolha uma conta corrente ativa do mesmo condomínio.');
            }
            $parent = (int)$row['id'];
        }
        $this->pdo->prepare('UPDATE erp_bank_accounts SET parent_bank_account_id = :p WHERE id = :id')->execute(['p' => $parent, 'id' => $investmentId]);
        Audit::record('update', 'erp_bank_accounts', $investmentId, ['parent_bank_account_id' => $investment['parent_bank_account_id']], ['parent_bank_account_id' => $parent]);
        return $this->account($user, $investmentId);
    }

    /** Aplicar: transferência da conta corrente ligada para a aplicação. */
    public function apply(User $user, int $investmentId, array $input): array
    {
        return $this->move($user, $investmentId, $input, 'apply');
    }

    /** Resgatar: transferência da aplicação para a conta corrente ligada. */
    public function redeem(User $user, int $investmentId, array $input): array
    {
        return $this->move($user, $investmentId, $input, 'redeem');
    }

    /**
     * Saldos separados (regra pura): soma por tipo de conta e o total, que é o Disponível.
     *
     * @param list<array{bank_account_id:int, closing_cents:int}> $balances
     * @param array<int, string> $kinds tipo por conta bancária
     */
    public static function split(array $balances, array $kinds): array
    {
        $totals = ['checking_cents' => 0, 'savings_cents' => 0, 'investments_cents' => 0];
        $map = ['corrente' => 'checking_cents', 'poupanca' => 'savings_cents', 'aplicacao' => 'investments_cents'];
        foreach ($balances as $b) {
            $totals[$map[$kinds[(int)$b['bank_account_id']] ?? 'corrente'] ?? 'checking_cents'] += (int)$b['closing_cents'];
        }
        return $totals + ['total_cents' => array_sum($totals)];
    }

    /** Posição numa data: cada conta com o tipo e a conta corrente ligada, e os totais separados. */
    public function positions(User $user, ?int $condominiumId, mixed $asOf): array
    {
        $balances = (new AccountingReports($this->pdo, $this->scope))->accountBalances($user, $condominiumId, is_string($asOf) && $asOf !== '' ? $asOf : date('Y-m-d'));
        $kinds = $this->kinds(array_column($balances['accounts'], 'bank_account_id'));
        $rows = array_map(static fn (array $b): array => $b + ['kind' => $kinds[(int)$b['bank_account_id']]['kind'] ?? 'corrente',
            'parent_bank_account_id' => $kinds[(int)$b['bank_account_id']]['parent'] ?? null], $balances['accounts']);
        return ['condominium_id' => $condominiumId, 'as_of' => $balances['as_of'], 'accounts' => $rows,
            'totals' => self::split($balances['accounts'], array_map(static fn (array $k): string => $k['kind'], $kinds))];
    }

    /** @param list<int|string> $ids @return array<int, array{kind:string, parent:?int}> */
    public function kinds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->pdo->query('SELECT id, kind, parent_bank_account_id FROM erp_bank_accounts WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = ['kind' => (string)$r['kind'], 'parent' => $r['parent_bank_account_id'] !== null ? (int)$r['parent_bank_account_id'] : null];
        }
        return $out;
    }

    private function move(User $user, int $investmentId, array $input, string $direction): array
    {
        $investment = $this->account($user, $investmentId);
        if ($investment['kind'] !== 'aplicacao') {
            throw new InvalidArgumentException('Escolha uma conta de tipo aplicação.');
        }
        if ($investment['parent_bank_account_id'] === null) {
            throw new InvalidArgumentException('Ligue a aplicação a uma conta corrente antes de aplicar ou resgatar.');
        }
        $parent = (int)$investment['parent_bank_account_id'];
        $label = $direction === 'apply' ? 'Aplicação' : 'Resgate';
        $description = is_string($input['description'] ?? null) && trim($input['description']) !== '' ? $input['description'] : $label . ' — ' . $investment['name'];
        $transfer = $this->shortcuts()->transfer($user, [
            'condominium_id' => $investment['condominium_id'],
            'from_bank_account_id' => $direction === 'apply' ? $parent : $investmentId,
            'to_bank_account_id' => $direction === 'apply' ? $investmentId : $parent,
            'amount_cents' => $input['amount_cents'] ?? null,
            'transferred_on' => $input['transferred_on'] ?? null,
            'description' => mb_substr($description, 0, 160),
        ]);
        return ['direction' => $direction, 'investment_account_id' => $investmentId, 'checking_account_id' => $parent, 'transfer' => $transfer];
    }

    /** @return array{id:int, condominium_id:int, name:string, kind:string, parent_bank_account_id:?int} */
    private function account(User $user, int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT id, condominium_id, name, kind, parent_bank_account_id FROM erp_bank_accounts WHERE id = :id AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied($row === false ? 0 : (int)$row['condominium_id']);   // 404, sem dizer se existe
        }
        return ['id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'name' => (string)$row['name'], 'kind' => (string)$row['kind'],
            'parent_bank_account_id' => $row['parent_bank_account_id'] !== null ? (int)$row['parent_bank_account_id'] : null];
    }

    private function shortcuts(): EntryShortcuts
    {
        return $this->shortcuts ??= new EntryShortcuts($this->pdo, $this->scope);
    }
}
