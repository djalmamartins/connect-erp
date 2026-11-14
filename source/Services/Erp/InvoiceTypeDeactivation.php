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
 * Tipos de faturas próprios sem uso inativados em lote (ADR 0297). A situação dos tipos de faturas (ADR 0296) aponta em
 * unused_ids os tipos à mão sem fatura no período: o tipo criado para uma cobrança que acabou (uma obra, um rateio) segue
 * ativo e aparece na escolha do tipo ao preparar a fatura. Inativar era um por um, pelo cadastro.
 *
 * Reusa o que já existe: os cadastros do registro (RegistryService::definition: invoice_types para a tabela, a permissão,
 * as recorrências e a coluna do override), a regra do override da ADR 0163, as faturas não canceladas (ADR 0033) e o
 * período por competência da situação (DiscountOverview::months(), no mês do banco). Inativar é o status inactive do
 * cadastro (com updated_by); nada é apagado, e as faturas já preparadas continuam apontando o tipo.
 *
 * - Só tipos do próprio condomínio: os padrões da Connect (sem condomínio) nunca entram, e nenhuma fatura é tocada.
 * - Entram: sem invoice_type_ids, os tipos próprios ativos sem fatura não cancelada nos últimos months meses (padrão
 *   DEFAULT_MONTHS, de MIN_MONTHS a MAX_MONTHS) e criados antes do período; os sem uso que têm fatura em preparação ou que
 *   encobrem um padrão ativo de mesmo nome ficam de fora e são contados em skipped_draft e skipped_overriding. Com
 *   invoice_type_ids, só os informados. Regra pura problem(): não encontrado entre os tipos do condomínio, já inativo,
 *   com fatura em preparação (a emissão não confere a situação do tipo), encobrindo um padrão ativo (o tipo inativo segue
 *   encobrindo o padrão na listagem da ADR 0163, e o nome sumiria da escolha), com fatura no período ou criado no período.
 * - Tudo ou nada, numa transação com os tipos travados (FOR UPDATE): a trava do tipo segura também a gravação de uma
 *   fatura nele até o fim (a chave estrangeira confere o tipo). Um recusado desfaz todos (RegistryValidationException com
 *   _form e _invoice_type). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro update por tipo (status, meses, última competência, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, recorrência e a última
 * competência: nenhum nome de tipo, conta, valor nem observação. Até MAX_TYPES por vez; motivo obrigatório.
 */
final class InvoiceTypeDeactivation
{
    public const MAX_TYPES = 500;
    public const DEFAULT_MONTHS = 24;
    public const MIN_MONTHS = 6;
    public const MAX_MONTHS = 120;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Motivo da recusa (regra pura); null quando o tipo pode ser inativado.
     *
     * @param array{status:string, created_on:string}|null $row
     */
    public static function problem(?array $row, ?string $lastCompetence, int $drafts, bool $overriding, string $fromMonth, int $months): ?string
    {
        return match (true) {
            $row === null => 'Tipo de fatura não encontrado entre os tipos deste condomínio.',
            $row['status'] !== 'active' => 'O tipo de fatura já está inativo.',
            $drafts > 0 => 'Há fatura em preparação com este tipo: emita ou cancele antes de inativar.',
            $overriding => 'Este tipo substitui um tipo padrão ativo de mesmo nome: inativá-lo esconderia os dois.',
            $lastCompetence !== null && $lastCompetence >= $fromMonth => sprintf('O tipo teve fatura nos últimos %d meses.', $months),
            $row['created_on'] >= $fromMonth . '-01' => sprintf('O tipo foi criado nos últimos %d meses.', $months),
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $months = self::DEFAULT_MONTHS;
        if (array_key_exists('months', $input) && $input['months'] !== null && $input['months'] !== '') {
            $months = $int($input['months']);
            if ($months === false || $months < self::MIN_MONTHS || $months > self::MAX_MONTHS) {
                throw new InvalidArgumentException(sprintf('Meses sem fatura: de %d a %d.', self::MIN_MONTHS, self::MAX_MONTHS));
            }
        }
        $ids = null;
        if (array_key_exists('invoice_type_ids', $input) && $input['invoice_type_ids'] !== null) {
            if (!is_array($input['invoice_type_ids']) || $input['invoice_type_ids'] === []) {
                throw new InvalidArgumentException('Tipos de faturas: informe a lista de ids ou deixe vazio para todos os sem uso.');
            }
            $ids = [];
            foreach ($input['invoice_type_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Tipos de faturas: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_TYPES) {
                throw new InvalidArgumentException(sprintf('Até %d tipos de faturas por vez.', self::MAX_TYPES));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O mês do banco, para comparar com as competências gravadas; o período é o da situação dos tipos de faturas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $period = DiscountOverview::months($today, (int)$months);
        $fromMonth = $period[0];
        $definition = RegistryService::definition('invoice_types');
        $types = (string)$definition['table'];
        $override = (string)$definition['override'];
        $recurrences = InvoiceTypeOverview::recurrences();

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'months' => (int)$months, 'from' => $fromMonth,
            'to' => $period[count($period) - 1], 'invoice_types' => [], 'skipped_draft' => 0, 'skipped_overriding' => 0];
        $this->pdo->beginTransaction();
        try {
            // Só os tipos do próprio condomínio: o padrão da Connect (condominium_id nulo) nunca entra.
            $sql = "SELECT t.id, t.recurrence, t.status, t.created_at,
                    EXISTS (SELECT 1 FROM {$types} s WHERE s.condominium_id IS NULL AND s.deleted_at IS NULL AND s.status = 'active'
                        AND s.{$override} = t.{$override}) AS overriding
                FROM {$types} t WHERE t.condominium_id = ? AND t.deleted_at IS NULL";
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND t.status = 'active'";
            } else {
                $sql .= ' AND t.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY t.id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'recurrence' => (string)$r['recurrence'], 'status' => (string)$r['status'],
                    'created_on' => substr((string)$r['created_at'], 0, 10), 'overriding' => (bool)$r['overriding']];
            }
            $last = [];
            $drafts = [];
            if ($byId !== []) {
                $in = implode(',', array_fill(0, count($byId), '?'));
                $stmt = $this->pdo->prepare("SELECT invoice_type_id, MAX(competence) AS last_competence, SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts
                    FROM erp_invoices WHERE condominium_id = ? AND status <> 'cancelled' AND invoice_type_id IN ({$in}) GROUP BY invoice_type_id");
                $stmt->execute(array_merge([(int)$condominium], array_keys($byId)));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $last[(int)$r['invoice_type_id']] = (string)$r['last_competence'];
                    $drafts[(int)$r['invoice_type_id']] = (int)$r['drafts'];
                }
            }
            $check = fn (?array $row, int $id): ?string => self::problem($row, $last[$id] ?? null, $drafts[$id] ?? 0, (bool)($row['overriding'] ?? false), $fromMonth, (int)$months);
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $id => $row) {
                    if ($check($row, $id) === null) {
                        $rows[] = $row;
                        continue;
                    }
                    $unused = !(isset($last[$id]) && $last[$id] >= $fromMonth) && $row['created_on'] < $fromMonth . '-01';
                    if ($unused && ($drafts[$id] ?? 0) > 0) {
                        $result['skipped_draft']++;        // sem uso, mas com fatura em preparação
                    } elseif ($unused && $row['overriding']) {
                        $result['skipped_overriding']++;   // sem uso, mas encobre um padrão ativo
                    }
                }
                if (count($rows) > self::MAX_TYPES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d tipos de faturas sem uso: inative em partes (escolha os ids).', self::MAX_TYPES));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = $check($byId[$id] ?? null, $id);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_invoice_type' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE {$types} SET status = 'inactive', updated_by = ? WHERE id = ? AND condominium_id = ? AND status = 'active'
                AND deleted_at IS NULL");
            foreach ($rows as $row) {
                $update->execute([(int)$user->id, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Um tipo de fatura mudou de situação enquanto a inativação era pedida.');
                }
                $result['invoice_types'][] = ['id' => $row['id'], 'recurrence' => $row['recurrence'], 'last_competence' => $last[$row['id']] ?? null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['invoice_types'] as $t) {
                Audit::record('update', $types, $t['id'], ['status' => 'active'], ['status' => 'inactive', 'months' => (int)$months,
                    'last_competence' => $t['last_competence'], 'reason' => $reason, 'batch' => count($result['invoice_types'])]);
            }
        }
        $byRecurrence = array_fill_keys(array_keys($recurrences), 0);
        foreach ($result['invoice_types'] as $t) {
            if (isset($byRecurrence[$t['recurrence']])) {
                $byRecurrence[$t['recurrence']]++;
            }
        }
        $result['by_recurrence'] = array_map(static fn (string $r, int $n): array => ['recurrence' => $r, 'recurrence_label' => (string)$recurrences[$r], 'invoice_types' => $n],
            array_keys($byRecurrence), array_values($byRecurrence));
        $result['count'] = count($result['invoice_types']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
