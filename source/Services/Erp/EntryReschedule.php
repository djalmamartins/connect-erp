<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Prorrogação de vencimento em lote (ADR 0197): muda o vencimento de várias contas em aberto de um condomínio de uma vez
 * (o fornecedor aceitou novas datas, o condomínio adiou um pagamento, um feriado bancário), para uma data só (due_on) ou
 * deslocando cada uma N dias (days, de −365 a 365).
 *
 * Só conta em aberto e válida. Recusa a conta com boleto ativo no banco (erp_bank_charges pending, registered ou paid): o
 * título registrado continuaria com o vencimento antigo; muda-se pela cobrança (só leitura aqui, nada do fluxo do banco).
 * A competência não muda (é do fato, não do vencimento).
 *
 * Cada conta é regravada pelo RegistryService::save do cadastro "entries", só com o vencimento trocado, então valem as
 * regras do formulário: competência fechada (ADR 0031/0100), trava de origem (fatura e acordo não mudam vencimento aqui,
 * ADR 0033/0039), trava de recibo e auditoria update; e um registro reschedule com o motivo.
 *
 * Tudo ou nada, numa transação com as contas travadas (FOR UPDATE): uma conta recusada desfaz todas
 * (RegistryValidationException com os erros e _entry). dry_run passa por tudo e desfaz no fim. Um condomínio só, do
 * escopo (fora = CondominiumAccessDenied, 404); até BatchSettlement::MAX_ENTRIES contas; motivo obrigatório.
 */
final class EntryReschedule
{
    public const MAX_DAYS = 365;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Novo vencimento (regra pura): a data fixa, ou o vencimento atual deslocado em dias. */
    public static function newDueOn(string $current, ?string $fixed, ?int $days): string
    {
        return $fixed ?? (new \DateTimeImmutable($current))->modify(sprintf('%+d days', (int)$days))->format('Y-m-d');
    }

    /** @param array<string, mixed> $input */
    public function reschedule(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $hasFixed = isset($input['due_on']) && $input['due_on'] !== '';
        $hasDays = isset($input['days']) && $input['days'] !== '';
        if ($hasFixed === $hasDays) {
            throw new InvalidArgumentException('Informe o novo vencimento (due_on) ou o deslocamento em dias (days), um dos dois.');
        }
        $fixed = null;
        $days = null;
        if ($hasFixed) {
            $date = is_string($input['due_on']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $input['due_on']) : false;
            if ($date === false || $date->format('Y-m-d') !== $input['due_on']) {
                throw new InvalidArgumentException('Novo vencimento: AAAA-MM-DD.');
            }
            $fixed = $input['due_on'];
        } else {
            $days = is_int($input['days']) || is_string($input['days'])
                ? filter_var($input['days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => -self::MAX_DAYS, 'max_range' => self::MAX_DAYS]]) : false;
            if ($days === false || $days === 0) {
                throw new InvalidArgumentException(sprintf('Deslocamento: de −%1$d a %1$d dias, diferente de zero.', self::MAX_DAYS));
            }
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da prorrogação (5 a 255 caracteres).');
        }
        $ids = BatchSettlement::ids($input['entry_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('entries')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'due_on' => $fixed, 'days' => $days, 'count' => count($ids), 'entries' => []];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . " FROM erp_entries WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $charges = $this->pdo->prepare("SELECT DISTINCT entry_id FROM erp_bank_charges WHERE status IN ('pending', 'registered', 'paid') AND entry_id IN ({$marks})");
            $charges->execute($ids);
            $charged = array_fill_keys(array_map('intval', $charges->fetchAll(PDO::FETCH_COLUMN)), true);
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;
                $problem = match (true) {
                    $row === null => 'Lançamento não encontrado neste condomínio.',
                    $row['status'] !== 'active' => 'Conta cancelada.',
                    $row['paid_on'] !== null => 'Conta já paga: o vencimento não muda depois da baixa.',
                    isset($charged[$id]) => 'Conta com boleto ativo no banco: mude o vencimento pela cobrança.',
                    default => null,
                };
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_entry' => (string)$id]);
                }
                $before = (string)$row['due_on'];
                $after = self::newDueOn($before, $fixed, $days);
                $result['entries'][] = ['id' => $id, 'description' => (string)$row['description'], 'competence' => (string)$row['competence'],
                    'before' => $before, 'after' => $after];
                if ($after === $before) {
                    continue;
                }
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $field === 'amount_cents' ? number_format(((int)$row[$field]) / 100, 2, ',', '') : $row[$field];
                }
                try {
                    // Também no dry_run: grava e desfaz no fim (rollback), para a prévia já trazer as recusas do formulário.
                    $registry->save($user, 'entries', array_merge($data, ['status' => $row['status'], 'due_on' => $after]), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_entry' => (string)$id]);
                }
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['entries'] as $entry) {
                if ($entry['before'] !== $entry['after']) {
                    Audit::record('reschedule', 'erp_entries', $entry['id'], ['due_on' => $entry['before']],
                        ['due_on' => $entry['after'], 'reason' => $reason, 'batch' => count($ids)]);
                }
            }
        }
        $result['changed'] = $dryRun ? 0 : count(array_filter($result['entries'], static fn (array $e): bool => $e['before'] !== $e['after']));
        return $result;
    }
}
