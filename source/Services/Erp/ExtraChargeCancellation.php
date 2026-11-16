<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Cobranças extras e multas avulsas lançadas por engano canceladas em lote (ADR 0289). A situação das cobranças (ADR
 * 0288) mostra as cobranças ativas e as contas delas; cancelar era uma por vez (EntryShortcuts::cancelCharge). Aqui várias
 * são canceladas de uma vez, com as mesmas regras do cancelamento de uma, numa transação só.
 *
 * Reusa o que já existe:
 * - as regras de EntryShortcuts::cancelCharge: a cobrança ativa, a competência aberta (RegistryService::isClosed, com a
 *   leitura travada do ADR 0100), nenhuma conta paga, nenhuma conta em acordo e nenhum boleto ativo; e a mesma gravação
 *   (as contas da cobrança inativas e a cobrança cancelled, com quem cancelou, quando e o motivo);
 * - as travas do banco de cancelar uma conta (PaymentLocks::find, ADR 0194): conta conciliada com o extrato ou com
 *   boleto pago pelo banco não é cancelada;
 * - o motivo (EntryShortcuts::reason) e os tipos (EntryShortcuts::CHARGE_KINDS).
 *
 * - Entram só os charge_ids informados (obrigatórios, até MAX_CHARGES): cancelar todas as cobranças ativas de uma vez
 *   seria perigoso. Regra pura problem(): não encontrada neste condomínio, já cancelada, competência fechada, conta paga,
 *   conta em acordo, boleto ativo ou trava do banco.
 * - Tudo ou nada, numa transação com as cobranças e as contas travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form e _charge). dry_run passa por tudo e desfaz.
 * - Auditoria: o mesmo registro update de erp_extra_charges do cancelamento de uma (com o tamanho do lote), só quando
 *   grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipos, contagens e valores:
 * nenhuma descrição, motivo, unidade nem quem lançou.
 */
final class ExtraChargeCancellation
{
    public const MAX_CHARGES = 500;
    /** Boleto ainda ativo no banco (o mesmo filtro do cancelamento de uma). */
    public const ACTIVE_BANK_CHARGE = ['pending', 'registered'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Motivo da recusa (regra pura); null quando a cobrança pode ser cancelada.
     *
     * @param array{status:string, closed:bool, entries:list<array{status:string, paid:bool, in_agreement:bool, active_boleto:bool, lock:?string}>}|null $charge
     */
    public static function problem(?array $charge): ?string
    {
        if ($charge === null) {
            return 'Cobrança não encontrada neste condomínio.';
        }
        if ($charge['status'] !== 'active') {
            return 'Já está cancelada.';
        }
        if ($charge['closed']) {
            return 'A competência está fechada. Reabra o fechamento mensal para cancelar.';
        }
        foreach ($charge['entries'] as $entry) {
            if ($entry['status'] === 'active' && $entry['paid']) {
                return 'Há conta paga. Estorne a baixa antes de cancelar.';
            }
            if ($entry['in_agreement']) {
                return 'Há conta renegociada em acordo. Cancele o acordo antes.';
            }
            if ($entry['active_boleto']) {
                return 'Há boleto ativo numa das contas. Cancele o boleto antes.';
            }
            if ($entry['lock'] !== null) {
                return $entry['lock'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = EntryShortcuts::reason($input['reason'] ?? null, 'do cancelamento');
        if (!is_array($input['charge_ids'] ?? null) || $input['charge_ids'] === []) {
            throw new InvalidArgumentException('Cobranças: informe a lista de ids.');
        }
        $ids = [];
        foreach ($input['charge_ids'] as $value) {
            $id = $int($value);
            if ($id === false) {
                throw new InvalidArgumentException('Cobranças: informe a lista de ids.');
            }
            $ids[$id] = $id;
        }
        if (count($ids) > self::MAX_CHARGES) {
            throw new InvalidArgumentException(sprintf('Até %d cobranças por vez.', self::MAX_CHARGES));
        }
        $ids = array_values($ids);
        sort($ids);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'charges' => []];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id, kind, status, competence FROM erp_extra_charges WHERE condominium_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $charges = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $charges[(int)$r['id']] = ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'],
                    'closed' => $this->registry->isClosed((int)$condominium, (string)$r['competence']), 'entries' => []];
            }
            if ($charges !== []) {
                $in = implode(',', array_fill(0, count($charges), '?'));
                $stmt = $this->pdo->prepare("SELECT id, extra_charge_id, amount_cents, status, paid_on, agreement_id FROM erp_entries
                    WHERE extra_charge_id IN ({$in}) AND deleted_at IS NULL ORDER BY id FOR UPDATE");
                $stmt->execute(array_keys($charges));
                $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $entryIds = array_map(static fn (array $e): int => (int)$e['id'], $entries);
                $locks = PaymentLocks::find($this->pdo, $entryIds);
                $boletos = [];
                if ($entryIds !== []) {
                    $states = implode(',', array_fill(0, count(self::ACTIVE_BANK_CHARGE), '?'));
                    $stmt = $this->pdo->prepare('SELECT DISTINCT entry_id FROM ' . \Source\Services\Erp\Banking\BankCharges::COVERAGE . ' cv WHERE status IN (' . $states . ') AND entry_id IN ('
                        . implode(',', array_fill(0, count($entryIds), '?')) . ')');
                    $stmt->execute(array_merge(self::ACTIVE_BANK_CHARGE, $entryIds));
                    $boletos = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                }
                foreach ($entries as $e) {
                    $charges[(int)$e['extra_charge_id']]['entries'][] = ['amount_cents' => (int)$e['amount_cents'], 'status' => (string)$e['status'],
                        'paid' => $e['paid_on'] !== null, 'in_agreement' => $e['agreement_id'] !== null, 'active_boleto' => isset($boletos[(int)$e['id']]),
                        'lock' => isset($locks[(int)$e['id']]) ? (string)PaymentLocks::removal($this->pdo, (int)$e['id'])['message'] : null];
                }
            }
            foreach ($ids as $id) {
                $problem = self::problem($charges[$id] ?? null);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_charge' => (string)$id]);
                }
            }
            $entriesUpdate = $this->pdo->prepare("UPDATE erp_entries SET status = 'inactive', updated_by = ? WHERE extra_charge_id = ? AND deleted_at IS NULL");
            $chargeUpdate = $this->pdo->prepare("UPDATE erp_extra_charges SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?
                WHERE id = ? AND condominium_id = ? AND status = 'active'");
            foreach ($ids as $id) {
                $entriesUpdate->execute([(int)$user->id, $id]);
                $chargeUpdate->execute([(int)$user->id, $reason, $id, (int)$condominium]);
                if ($chargeUpdate->rowCount() !== 1) {
                    throw new DomainException('Uma cobrança mudou de situação enquanto o cancelamento era pedido.');
                }
                $result['charges'][] = ['id' => $id, 'kind' => $charges[$id]['kind'], 'entries' => count($charges[$id]['entries']),
                    'amount_cents' => array_sum(array_map(static fn (array $e): int => $e['status'] === 'active' ? $e['amount_cents'] : 0, $charges[$id]['entries']))];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['charges'] as $c) {
                Audit::record('update', 'erp_extra_charges', $c['id'], ['status' => 'active'], ['status' => 'cancelled', 'cancel_reason' => $reason,
                    'batch' => count($result['charges'])]);
            }
        }
        $byKind = array_fill_keys(array_keys(EntryShortcuts::CHARGE_KINDS), ['charges' => 0, 'amount_cents' => 0]);
        foreach ($result['charges'] as $c) {
            if (isset($byKind[$c['kind']])) {
                $byKind[$c['kind']]['charges']++;
                $byKind[$c['kind']]['amount_cents'] += $c['amount_cents'];
            }
        }
        $result['by_kind'] = array_map(static fn (string $kind, array $n): array => ['kind' => $kind, 'kind_label' => EntryShortcuts::CHARGE_KINDS[$kind]] + $n,
            array_keys($byKind), array_values($byKind));
        $result['count'] = count($result['charges']);
        $result['entries'] = array_sum(array_column($result['charges'], 'entries'));
        $result['amount_cents'] = array_sum(array_column($result['charges'], 'amount_cents'));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
