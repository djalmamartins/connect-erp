<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\ImportRules;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Audit;

/**
 * Nova conferência do extrato pendente (ADR 0209): roda de novo, sobre as linhas do extrato ainda pendentes (AP-140,
 * ADR 0058), o mesmo cruzamento da importação (ImportRules::match: mesmo sentido, mesmo valor, até MATCH_DAYS dias e
 * exatamente um candidato; nunca escolhe sozinho entre dois). Resolve o caso do dia a dia: o extrato foi importado antes
 * da baixa (o morador pagou, o boleto foi baixado depois, a transferência foi lançada depois) e a linha ficou pendente
 * para sempre, à espera de alguém conciliar à mão, uma por uma.
 *
 * - Candidatos: lançamentos pagos na mesma conta bancária e transferências da conta, ainda não conciliados, perto das
 *   datas das linhas (as mesmas consultas da importação). Linha ignorada ou já conciliada não entra.
 * - Linha que não fecha com exatamente um candidato continua pendente (a tela de conciliação à mão segue valendo).
 * - Uma conta bancária ou todas as ativas do condomínio.
 * - Numa transação com as linhas travadas (FOR UPDATE), com a mesma condição de pendente no UPDATE: o que já foi resolvido
 *   por outra pessoa não é sobrescrito. dry_run passa por tudo e desfaz no fim. Auditoria update por linha conciliada
 *   (pendente → conciliada, lançamento ou transferência, regra rematch), só quando grava. Nada é enviado nem ao banco.
 *
 * Escopo (ADR-002): condomínio do escopo (fora = CondominiumAccessDenied, 404); conta de outro condomínio = 404
 * (RegistryNotFound). Saem ids, datas, valores e o sentido; o histórico (memo) do banco não sai (pode ter nome de pagador).
 */
final class StatementRematch
{
    public const MAX_LINES = 2000;
    public const NOTE = 'Conciliação automática na nova conferência do extrato (ADR 0209).';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @param array<string, mixed> $input */
    public function rematch(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $bank = $input['bank_account_id'] ?? null;
        if ($bank !== null && $bank !== '') {
            $bank = is_int($bank) || is_string($bank) ? filter_var($bank, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($bank === false) {
                throw new InvalidArgumentException('Conta bancária inválida.');
            }
        } else {
            $bank = null;
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $stmt = $this->pdo->prepare("SELECT id, name FROM erp_bank_accounts WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'"
            . ($bank !== null ? ' AND id = ?' : '') . ' ORDER BY name, id');
        $stmt->execute($bank !== null ? [(int)$condominium, (int)$bank] : [(int)$condominium]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($bank !== null && $accounts === []) {
            throw new RegistryNotFound();
        }

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'match_days' => ImportRules::MATCH_DAYS, 'pending_before' => 0, 'matched' => 0,
            'still_pending' => 0, 'accounts' => [], 'lines' => []];
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare("UPDATE erp_bank_statement_lines SET status = 'matched', entry_id = ?, transfer_id = ?, note = ?, resolved_by = ?, resolved_at = ?
                WHERE id = ? AND status = 'unmatched'");
            foreach ($accounts as $account) {
                $accountId = (int)$account['id'];
                $stmt = $this->pdo->prepare("SELECT id, posted_on, amount_cents FROM erp_bank_statement_lines WHERE bank_account_id = ? AND condominium_id = ?
                    AND status = 'unmatched' ORDER BY posted_on, id LIMIT " . (self::MAX_LINES + 1) . ' FOR UPDATE');
                $stmt->execute([$accountId, (int)$condominium]);
                $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($lines) > self::MAX_LINES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d linhas pendentes na conta: concilie em partes, conta por conta.', self::MAX_LINES));
                }
                $matched = 0;
                if ($lines !== []) {
                    $dates = array_map(static fn (array $l): string => substr((string)$l['posted_on'], 0, 10), $lines);
                    $from = (new \DateTimeImmutable(min($dates)))->modify('-' . ImportRules::MATCH_DAYS . ' days')->format('Y-m-d');
                    $to = (new \DateTimeImmutable(max($dates)))->modify('+' . ImportRules::MATCH_DAYS . ' days')->format('Y-m-d');
                    $matches = ImportRules::match(array_map(static fn (array $l): array => ['fitid' => (string)$l['id'], 'posted_on' => substr((string)$l['posted_on'], 0, 10),
                        'amount_cents' => (int)$l['amount_cents']], $lines), $this->candidates($accountId, $from, $to));
                    foreach ($lines as $line) {
                        $key = $matches[(string)$line['id']] ?? null;
                        if ($key === null) {
                            continue;
                        }
                        $entryId = $key[0] === 'e' ? (int)substr($key, 1) : null;
                        $transferId = $key[0] === 't' ? (int)substr($key, 1) : null;
                        $update->execute([$entryId, $transferId, self::NOTE, (int)$user->id, date('Y-m-d H:i:s'), (int)$line['id']]);
                        if ($update->rowCount() !== 1) {
                            continue;   // resolvida por outra pessoa no meio
                        }
                        $matched++;
                        $result['lines'][] = ['id' => (int)$line['id'], 'bank_account_id' => $accountId, 'posted_on' => substr((string)$line['posted_on'], 0, 10),
                            'amount_cents' => (int)$line['amount_cents'], 'direction' => (int)$line['amount_cents'] >= 0 ? 'in' : 'out',
                            'entry_id' => $entryId, 'transfer_id' => $transferId];
                    }
                }
                $result['accounts'][] = ['bank_account_id' => $accountId, 'name' => (string)$account['name'], 'pending_before' => count($lines),
                    'matched' => $matched, 'still_pending' => count($lines) - $matched];
                $result['pending_before'] += count($lines);
                $result['matched'] += $matched;
            }
            $result['still_pending'] = $result['pending_before'] - $result['matched'];
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['lines'] as $line) {
                Audit::record('update', 'erp_bank_statement_lines', $line['id'], ['status' => 'unmatched'],
                    ['status' => 'matched', 'entry_id' => $line['entry_id'], 'transfer_id' => $line['transfer_id'], 'rule' => 'rematch']);
            }
        }
        $result['changed'] = $dryRun ? 0 : $result['matched'];
        return $result;
    }

    /** @return list<array{key:string, direction:string, on:string, amount_cents:int}> as mesmas da importação (Imports::applyOfx) */
    private function candidates(int $accountId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare("SELECT e.id, e.nature, e.paid_on, e.amount_cents FROM erp_entries e LEFT JOIN erp_bank_statement_lines l ON l.entry_id = e.id
            WHERE e.bank_account_id = ? AND e.paid_on BETWEEN ? AND ? AND e.status = 'active' AND e.deleted_at IS NULL AND l.id IS NULL");
        $stmt->execute([$accountId, $from, $to]);
        $candidates = array_map(static fn (array $r): array => ['key' => 'e' . $r['id'], 'direction' => $r['nature'] === 'receita' ? 'in' : 'out',
            'on' => substr((string)$r['paid_on'], 0, 10), 'amount_cents' => (int)$r['amount_cents']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare("SELECT t.id, t.from_bank_account_id, t.transferred_on, t.amount_cents FROM erp_bank_transfers t
            LEFT JOIN erp_bank_statement_lines l ON l.transfer_id = t.id AND l.bank_account_id = ?
            WHERE (t.from_bank_account_id = ? OR t.to_bank_account_id = ?) AND t.status = 'active' AND t.transferred_on BETWEEN ? AND ? AND l.id IS NULL");
        $stmt->execute([$accountId, $accountId, $accountId, $from, $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $candidates[] = ['key' => 't' . $r['id'], 'direction' => (int)$r['from_bank_account_id'] === $accountId ? 'out' : 'in',
                'on' => substr((string)$r['transferred_on'], 0, 10), 'amount_cents' => (int)$r['amount_cents']];
        }
        return $candidates;
    }
}
