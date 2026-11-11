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
 * Reclassificação em lote (ADR 0196): troca a conta do plano de contas e/ou o centro de custo de vários lançamentos de
 * um condomínio de uma vez (revisão do mês pelo contador, despesa lançada na conta errada, centro de custo esquecido),
 * em vez de abrir lançamento por lançamento.
 *
 * Cada lançamento é regravado pelo RegistryService::save do cadastro "entries", só com a conta e/ou o centro de custo
 * trocados; o resto vai como está. Assim valem as mesmas regras do formulário:
 * - conta analítica ativa, da mesma natureza do lançamento (ref_match), do padrão ou do condomínio;
 * - centro de custo ativo do padrão ou do condomínio;
 * - competência fechada (ADR 0031/0100);
 * - trava de origem (fatura, acordo; ADR 0033/0039): a conta desses não muda aqui; o centro de custo pode;
 * - auditoria update de cada lançamento, e um registro reclassify com o motivo.
 * cost_center_id = 0 tira o centro de custo.
 *
 * Tudo ou nada, numa transação com os lançamentos travados (FOR UPDATE): um lançamento recusado desfaz todos
 * (RegistryValidationException com os erros e _entry). dry_run passa por tudo (inclusive as regras do formulário) e
 * desfaz no fim: lista o antes e o depois, ou as recusas, sem gravar.
 * Um condomínio só, do escopo (fora = CondominiumAccessDenied, 404); até BatchSettlement::MAX_ENTRIES lançamentos;
 * motivo obrigatório (5 a 255 caracteres).
 */
final class EntryReclassification
{
    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @param array<string, mixed> $input */
    public function reclassify(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $changes = [];
        if (array_key_exists('chart_account_id', $input) && $input['chart_account_id'] !== null && $input['chart_account_id'] !== '') {
            $account = filter_var($input['chart_account_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($account === false) {
                throw new InvalidArgumentException('Conta do plano de contas inválida.');
            }
            $changes['chart_account_id'] = $account;
        }
        if (array_key_exists('cost_center_id', $input) && $input['cost_center_id'] !== null && $input['cost_center_id'] !== '') {
            $center = filter_var($input['cost_center_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($center === false) {
                throw new InvalidArgumentException('Centro de custo inválido (0 tira o centro de custo).');
            }
            $changes['cost_center_id'] = $center === 0 ? null : $center;
        }
        if ($changes === []) {
            throw new InvalidArgumentException('Informe a nova conta do plano de contas e/ou o novo centro de custo.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da reclassificação (5 a 255 caracteres).');
        }
        $ids = BatchSettlement::ids($input['entry_ids'] ?? null);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('entries')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'changes' => $changes, 'count' => count($ids), 'entries' => []];
        $this->pdo->beginTransaction();
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . " FROM erp_entries WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ({$marks}) FOR UPDATE");
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $missing = array_values(array_diff($ids, array_keys($rows)));
            if ($missing !== []) {
                throw new RegistryValidationException(['_form' => 'Lançamento(s) não encontrado(s) neste condomínio: ' . implode(', ', $missing) . '.', '_entry' => (string)$missing[0]]);
            }
            foreach ($ids as $id) {
                $row = $rows[$id];
                $before = ['chart_account_id' => $row['chart_account_id'] === null ? null : (int)$row['chart_account_id'],
                    'cost_center_id' => $row['cost_center_id'] === null ? null : (int)$row['cost_center_id']];
                $after = $changes + $before;
                $result['entries'][] = ['id' => $id, 'description' => (string)$row['description'], 'competence' => (string)$row['competence'],
                    'before' => $before, 'after' => $after];
                if ($after === $before) {
                    continue;
                }
                // Também no dry_run: grava e desfaz no fim (rollback), para a prévia já trazer as recusas do formulário.
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $field === 'amount_cents' ? number_format(((int)$row[$field]) / 100, 2, ',', '') : $row[$field];
                }
                try {
                    $registry->save($user, 'entries', array_merge($data, ['status' => $row['status']], $changes), $id);
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
                    Audit::record('reclassify', 'erp_entries', $entry['id'], $entry['before'], $entry['after'] + ['reason' => $reason, 'batch' => count($ids)]);
                }
            }
        }
        $result['changed'] = $dryRun ? 0 : count(array_filter($result['entries'], static fn (array $e): bool => $e['before'] !== $e['after']));
        return $result;
    }
}
