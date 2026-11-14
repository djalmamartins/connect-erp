<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Inativação em lote dos centros de custo parados (ADR 0273). O cadastro de centros de custo (CN-002) agrupa lançamentos
 * por área ou projeto ("Obra do bloco B", "Reforma da piscina"). Terminado o projeto, o centro de custo segue "Ativo": entra
 * nas listas do lançamento, da conta bancária e dos lançamentos fixos, e nos relatórios gerenciais com zero. Hoje só se
 * inativa um por um, pelo formulário.
 *
 * Reusa o que já existe: cada centro de custo é regravado pelo RegistryService::save do cadastro "cost_centers" só com a
 * situação "Inativo" (valem as regras e a auditoria update do formulário). Antes de gravar, a regra pura do formulário
 * (VehicleUnitRelease::formError, sobre RegistryValidator::validate) aponta o cadastro antigo que o formulário não regrava.
 * Lançamentos, saldos de abertura e lançamentos fixos não mudam. Nada é apagado e nada é enviado.
 *
 * - Entram: os centros de custo ativos do próprio condomínio (os padrão da Connect, sem condomínio, ficam de fora) sem
 *   lançamento com competência a partir de since (o mês atual menos idle_months - 1; padrão 12 meses), ou só os
 *   cost_center_ids informados. Regra pura problem(): centro de custo não encontrado no condomínio (ou padrão da
 *   Connect), já inativo, com lançamento recente, com conta em aberto (sem pagamento), usado por lançamento fixo ativo,
 *   padrão de uma conta bancária ativa ou com erro no formulário. Sem ids, os parados com conta em aberto, fixo ativo,
 *   conta bancária ou erro no formulário ficam de fora e as contagens saem em skipped_open, skipped_recurring,
 *   skipped_bank_default e skipped_invalid.
 * - Tudo ou nada, numa transação com os centros de custo travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _cost_center). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro deactivate_idle por centro de custo (última competência com
 *   lançamento, meses parados e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id e a última competência com
 * lançamento: nenhum código, nome ou observação. Até MAX_CENTERS por vez; motivo obrigatório.
 */
final class CostCenterDeactivation
{
    public const MAX_CENTERS = 500;
    public const DEFAULT_IDLE_MONTHS = 12;
    public const MAX_IDLE_MONTHS = 120;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Primeira competência que conta como movimento recente: o mês atual menos (meses parados - 1) (regra pura). */
    public static function since(string $current, int $idleMonths): string
    {
        return (new DateTimeImmutable($current . '-01'))->modify(sprintf('%+d months', 1 - $idleMonths))->format('Y-m');
    }

    /**
     * Motivo da recusa antes do formulário (regra pura); null quando o centro de custo pode ser inativado.
     *
     * @param array{recent:bool, open:bool, recurring:bool, bank_default:bool} $uses
     */
    public static function problem(?array $row, array $uses, ?string $formError = null): ?string
    {
        return match (true) {
            $row === null => 'Centro de custo não encontrado neste condomínio (os padrão da Connect não entram).',
            ($row['status'] ?? '') !== 'active' => 'O centro de custo já está inativo.',
            $uses['recent'] => 'O centro de custo tem lançamento recente: não está parado.',
            $uses['open'] => 'O centro de custo tem conta em aberto: dê baixa ou troque o centro de custo antes.',
            $uses['recurring'] => 'O centro de custo é usado por lançamento fixo ativo: encerre ou troque o lançamento fixo antes.',
            $uses['bank_default'] => 'O centro de custo é o padrão de uma conta bancária ativa: troque na conta bancária antes.',
            $formError !== null => 'O formulário não regrava o centro de custo (' . $formError . '). Corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Ids (dos informados) que aparecem numa consulta de uso.
     *
     * @param list<int> $ids
     * @param list<mixed> $params parâmetros antes dos ids
     * @return array<int, true>
     */
    private function used(string $sql, array $params, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(str_replace('{in}', implode(',', array_fill(0, count($ids), '?')), $sql));
        $stmt->execute(array_merge($params, $ids));
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Dados do formulário a partir da linha do cadastro.
     *
     * @param list<string> $fields
     * @return array<string, ?string>
     */
    private static function formData(array $fields, array $row): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $row[$field] === null ? null : (string)$row[$field];
        }
        return $data;
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $idle = $input['idle_months'] ?? null;
        $idle = $idle === null || $idle === '' ? self::DEFAULT_IDLE_MONTHS : $int($idle);
        if ($idle === false || $idle > self::MAX_IDLE_MONTHS) {
            throw new InvalidArgumentException(sprintf('Meses parados: de 1 a %d.', self::MAX_IDLE_MONTHS));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('cost_center_ids', $input) && $input['cost_center_ids'] !== null) {
            if (!is_array($input['cost_center_ids']) || $input['cost_center_ids'] === []) {
                throw new InvalidArgumentException('Centros de custo: informe a lista de ids ou deixe vazio para todos os parados.');
            }
            $ids = [];
            foreach ($input['cost_center_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Centros de custo: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_CENTERS) {
                throw new InvalidArgumentException(sprintf('Até %d centros de custo por vez.', self::MAX_CENTERS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $c = (int)$condominium;
        $since = self::since(date('Y-m'), (int)$idle);
        $definition = RegistryService::definition('cost_centers');
        $fields = array_keys($definition['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => $c, 'dry_run' => $dryRun, 'idle_months' => (int)$idle, 'since_competence' => $since, 'reason' => $reason,
            'skipped_open' => 0, 'skipped_recurring' => 0, 'skipped_bank_default' => 0, 'skipped_invalid' => 0, 'cost_centers' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $ids === null
                ? $this->pdo->prepare("SELECT {$columns} FROM erp_cost_centers WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY id FOR UPDATE")
                : $this->pdo->prepare("SELECT {$columns} FROM erp_cost_centers WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute($ids === null ? [$c] : array_merge([$c], $ids));
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = $r;
            }
            $found = array_keys($byId);
            $recent = $this->used('SELECT DISTINCT cost_center_id FROM erp_entries WHERE deleted_at IS NULL AND competence >= ? AND cost_center_id IN ({in})', [$since], $found);
            $open = $this->used("SELECT DISTINCT cost_center_id FROM erp_entries WHERE deleted_at IS NULL AND status = 'active' AND paid_on IS NULL AND cost_center_id IN ({in})",
                [], $found);
            $recurring = $this->used("SELECT DISTINCT cost_center_id FROM erp_recurring_entries WHERE deleted_at IS NULL AND status = 'active' AND cost_center_id IN ({in})",
                [], $found);
            $bank = $this->used("SELECT DISTINCT cost_center_id FROM erp_bank_accounts WHERE deleted_at IS NULL AND status = 'active' AND cost_center_id IN ({in})", [], $found);
            $last = [];
            if ($found !== []) {
                $stmt = $this->pdo->prepare('SELECT cost_center_id, MAX(competence) AS last_competence FROM erp_entries WHERE deleted_at IS NULL AND cost_center_id IN ('
                    . implode(',', array_fill(0, count($found), '?')) . ') GROUP BY cost_center_id');
                $stmt->execute($found);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $last[(int)$r['cost_center_id']] = $r['last_competence'] === null ? null : (string)$r['last_competence'];
                }
            }
            $uses = static fn (int $id): array => ['recent' => isset($recent[$id]), 'open' => isset($open[$id]), 'recurring' => isset($recurring[$id]),
                'bank_default' => isset($bank[$id])];
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $id => $r) {
                    $u = $uses($id);
                    if ($u['recent']) {
                        continue;
                    }
                    match (true) {
                        $u['open'] => $result['skipped_open']++,
                        $u['recurring'] => $result['skipped_recurring']++,
                        $u['bank_default'] => $result['skipped_bank_default']++,
                        VehicleUnitRelease::formError($definition, self::formData($fields, $r)) !== null => $result['skipped_invalid']++,
                        default => $rows[] = $r,
                    };
                }
                if (count($rows) > self::MAX_CENTERS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d centros de custo a inativar: inative em partes (escolha os ids).', self::MAX_CENTERS));
                }
            } else {
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $formError = $row === null ? null : VehicleUnitRelease::formError($definition, self::formData($fields, $row));
                    $problem = self::problem($row, $uses($id), $formError);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_cost_center' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                try {
                    $registry->save($user, 'cost_centers', array_merge(self::formData($fields, $row), ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_cost_center' => (string)$row['id']]);
                }
                $result['cost_centers'][] = ['id' => (int)$row['id'], 'last_competence' => $last[(int)$row['id']] ?? null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['cost_centers'] as $cc) {
                Audit::record('deactivate_idle', 'erp_cost_centers', $cc['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'last_competence' => $cc['last_competence'], 'idle_months' => (int)$idle, 'reason' => $reason,
                        'batch' => count($result['cost_centers'])]);
            }
        }
        $result['count'] = count($result['cost_centers']);
        $result['never_used'] = count(array_filter($result['cost_centers'], static fn (array $cc): bool => $cc['last_competence'] === null));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
