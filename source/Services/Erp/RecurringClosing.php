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
 * Encerramento em lote dos lançamentos fixos que já passaram da última competência (ADR 0271). O cadastro de lançamentos
 * fixos (AP-021) tem a "Última competência", e a geração do mês (ADR 0027) e o reajuste (ADR 0204) já ignoram o modelo
 * encerrado; a situação, porém, segue "Ativo" até alguém abrir o formulário. O modelo encerrado em março continua na lista
 * dos ativos e, enquanto estiver ativo, a inativação em lote dos fornecedores recusa o fornecedor ("encerre a recorrência
 * antes"). O encerramento dos contratos (ADR 0245) só aponta os fixos para revisão (recurring_to_review).
 *
 * Reusa o que já existe: cada modelo é regravado pelo RegistryService::save do cadastro "recurring_entries" só com a
 * situação "Inativo", como no reajuste (ADR 0204: o valor em reais e os demais campos como estão), valendo as regras e a
 * auditoria update do formulário. Antes de gravar, a regra pura do formulário (VehicleUnitRelease::formError, sobre
 * RegistryValidator::validate) e a checagem dos vínculos ativos (conta, centro de custo, conta bancária e fornecedor)
 * apontam o cadastro que o formulário não regrava. Lançamentos já gerados não mudam. Nada é apagado e nada é enviado.
 *
 * - Entram: os modelos ativos do condomínio com a última competência antes de before_competence (padrão: o mês atual;
 *   nunca depois dele), ou só os recurring_ids informados. Regra pura problem(): modelo não encontrado no condomínio, já
 *   inativo, sem última competência (sem fim: informe no formulário), com a última competência ainda por vir (o próprio
 *   mês vale), com vínculo inativo ou com erro no formulário (corrija no cadastro). Sem ids, esses dois últimos ficam de
 *   fora e as contagens saem em skipped_inactive_link e skipped_invalid.
 * - Tudo ou nada, numa transação com os modelos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _recurring). dry_run passa por tudo e desfaz.
 * - monthly_cents soma o valor mensal dos encerrados por natureza (receita e despesa). suppliers_freed conta os
 *   fornecedores dos encerrados que ficam sem nenhum lançamento fixo ativo (podem ser inativados em lote).
 * - Auditoria: a update do formulário e um registro close_ended por modelo (última competência, valor e motivo), só
 *   quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, natureza, última competência e
 * valor: nenhuma descrição, fornecedor, conta ou observação. Até MAX_TEMPLATES por vez; motivo obrigatório.
 */
final class RecurringClosing
{
    public const MAX_TEMPLATES = 500;
    public const LINKS = ['chart_account_id' => 'erp_chart_accounts', 'cost_center_id' => 'erp_cost_centers', 'bank_account_id' => 'erp_bank_accounts',
        'supplier_id' => 'erp_suppliers'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o modelo pode ser encerrado. */
    public static function problem(?array $row, string $before, bool $inactiveLink = false, ?string $formError = null): ?string
    {
        $end = (string)($row['ends_competence'] ?? '');
        return match (true) {
            $row === null => 'Lançamento fixo não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O lançamento fixo já está inativo.',
            $end === '' => 'Lançamento fixo sem última competência (sem fim): informe a última competência no formulário.',
            $end >= $before => sprintf('A última competência (%s) ainda não passou.', $end),
            $inactiveLink => 'Conta, centro de custo, conta bancária ou fornecedor do lançamento fixo está inativo: o formulário não regrava. Corrija no cadastro.',
            $formError !== null => 'O formulário não regrava o lançamento fixo (' . $formError . '). Corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Linhas com algum vínculo (conta, centro de custo, conta bancária ou fornecedor) excluído ou inativo.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function inactiveLinks(array $rows): array
    {
        $out = [];
        foreach (self::LINKS as $field => $table) {
            $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r[$field] ?? 0), $rows))));
            if ($ids === []) {
                continue;
            }
            $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE deleted_at IS NULL AND status = 'active' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $active = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
            foreach ($rows as $r) {
                $id = (int)($r[$field] ?? 0);
                if ($id > 0 && !isset($active[$id])) {
                    $out[(int)$r['id']] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Dados do formulário a partir da linha do cadastro (o valor em reais, como no reajuste).
     *
     * @param list<string> $fields
     * @return array<string, ?string>
     */
    private static function formData(array $fields, array $row): array
    {
        $data = [];
        foreach ($fields as $field) {
            $value = $row[$field];
            $data[$field] = match (true) {
                $value === null => null,
                $field === 'amount_cents' => number_format(((int)$value) / 100, 2, ',', ''),
                default => (string)$value,
            };
        }
        return $data;
    }

    /** @param array<string, mixed> $input */
    public function close(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $current = date('Y-m');
        $before = $input['before_competence'] ?? null;
        $before = $before === null || $before === '' ? $current : $before;
        if (!is_string($before) || !RecurringEntries::isCompetence($before) || $before > $current) {
            throw new InvalidArgumentException('Competência de corte: AAAA-MM, até o mês atual.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('recurring_ids', $input) && $input['recurring_ids'] !== null) {
            if (!is_array($input['recurring_ids']) || $input['recurring_ids'] === []) {
                throw new InvalidArgumentException('Lançamentos fixos: informe a lista de ids ou deixe vazio para todos os encerrados.');
            }
            $ids = [];
            foreach ($input['recurring_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Lançamentos fixos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_TEMPLATES) {
                throw new InvalidArgumentException(sprintf('Até %d lançamentos fixos por vez.', self::MAX_TEMPLATES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $definition = RegistryService::definition('recurring_entries');
        $fields = array_keys($definition['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before_competence' => $before, 'reason' => $reason, 'skipped_inactive_link' => 0,
            'skipped_invalid' => 0, 'recurring' => []];
        $suppliersFreed = 0;
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_recurring_entries WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND ends_competence IS NOT NULL AND ends_competence < ? ORDER BY id FOR UPDATE");
                $stmt->execute([(int)$condominium, $before]);
                $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $inactive = $this->inactiveLinks($found);
                $rows = [];
                foreach ($found as $r) {
                    if (isset($inactive[(int)$r['id']])) {
                        $result['skipped_inactive_link']++;
                    } elseif (VehicleUnitRelease::formError($definition, self::formData($fields, $r)) !== null) {
                        $result['skipped_invalid']++;
                    } else {
                        $rows[] = $r;
                    }
                }
                if (count($rows) > self::MAX_TEMPLATES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d lançamentos fixos a encerrar: encerre em partes (escolha os ids).', self::MAX_TEMPLATES));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_recurring_entries WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $inactive = $this->inactiveLinks(array_values($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $formError = $row === null ? null : VehicleUnitRelease::formError($definition, self::formData($fields, $row));
                    $problem = self::problem($row, $before, isset($inactive[$id]), $formError);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_recurring' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                try {
                    $registry->save($user, 'recurring_entries', array_merge(self::formData($fields, $row), ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_recurring' => (string)$row['id']]);
                }
                $result['recurring'][] = ['id' => (int)$row['id'], 'nature' => (string)$row['nature'], 'ends_competence' => (string)$row['ends_competence'],
                    'amount_cents' => (int)$row['amount_cents']];
            }
            $suppliers = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['supplier_id'] ?? 0), $rows))));
            if ($suppliers !== []) {
                $stmt = $this->pdo->prepare("SELECT DISTINCT supplier_id FROM erp_recurring_entries WHERE status = 'active' AND deleted_at IS NULL AND supplier_id IN ("
                    . implode(',', array_fill(0, count($suppliers), '?')) . ')');
                $stmt->execute($suppliers);
                $suppliersFreed = count($suppliers) - count($stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['recurring'] as $r) {
                Audit::record('close_ended', 'erp_recurring_entries', $r['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'ends_competence' => $r['ends_competence'], 'amount_cents' => $r['amount_cents'], 'reason' => $reason,
                        'batch' => count($result['recurring'])]);
            }
        }
        $monthly = ['receita' => 0, 'despesa' => 0];
        foreach ($result['recurring'] as $r) {
            $monthly[$r['nature']] = ($monthly[$r['nature']] ?? 0) + $r['amount_cents'];
        }
        $result['count'] = count($result['recurring']);
        $result['monthly_cents'] = $monthly;
        $result['suppliers_freed'] = $suppliersFreed;
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
