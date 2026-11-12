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
 * Cancelamento em lote dos empenhos em aberto há muito tempo (ADR 0239). A posição dos empenhos (ADR 0238) mostra
 * quanto está empenhado e não liquidado há mais de 60 ou 90 dias: reservas de despesa que não vão mais acontecer
 * (orçamento recusado, obra desistida) e que seguem inflando o valor empenhado. Hoje cada uma é cancelada à mão no
 * formulário do cadastro (AP-128).
 *
 * Reusa o que já existe: cada empenho é regravado pelo RegistryService::save do cadastro "commitments" só com a situação
 * "Cancelado" (valem as regras, as referências e a auditoria update do formulário), no mesmo desenho do destino dos
 * achados e perdidos fora do prazo (ADR 0233). Nada é apagado e nada é enviado.
 *
 * - Entram: os empenhos em aberto do condomínio feitos há mais de older_than_days dias (padrão 90, de 30 a 3650), ou só
 *   os commitment_ids informados. Regra pura problem(): empenho não encontrado no condomínio, que não está em aberto
 *   (liquidado ou cancelado), com lançamento ligado (o pagamento já aconteceu: liquide no cadastro), ainda dentro do
 *   prazo, ou cujo fornecedor ficou inativo ou cujo documento foi removido (o formulário não regrava: corrija no cadastro).
 *   Sem ids, os de fornecedor inativo ou documento removido ficam de fora e a contagem sai em skipped_inactive_link; os de
 *   lançamento ligado também ficam de fora, em skipped_with_entry.
 * - Tudo ou nada, numa transação com os empenhos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _commitment). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro cancel por empenho (data, dias em aberto, valor e motivo), só quando
 *   grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, data, dias em aberto e valor:
 * nenhuma descrição, fornecedor, documento ou observação. Até MAX_COMMITMENTS por vez; motivo obrigatório.
 */
final class CommitmentCancellation
{
    public const MAX_COMMITMENTS = 500;
    public const MIN_DAYS = 30;
    public const DEFAULT_DAYS = 90;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o empenho pode ser cancelado. $cutoff = último dia aceito. */
    public static function problem(?array $row, string $cutoff, bool $linksActive = true): ?string
    {
        return match (true) {
            $row === null => 'Empenho não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O empenho não está em aberto (já foi liquidado ou cancelado).',
            ($row['entry_id'] ?? null) !== null => 'O empenho tem lançamento ligado: o pagamento aconteceu, liquide no cadastro.',
            substr((string)($row['committed_on'] ?? ''), 0, 10) > $cutoff => 'O empenho ainda está dentro do prazo.',
            !$linksActive => 'O fornecedor do empenho está inativo ou o documento foi removido: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Ids dos empenhos cujo fornecedor (se houver) segue ativo e cujo documento (se houver) segue no Disco Virtual do
     * condomínio: o formulário só regrava esses.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function activeLinks(int $condominium, array $rows): array
    {
        $suppliers = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['supplier_id'] ?? 0), $rows))));
        $documents = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['document_id'] ?? 0), $rows))));
        $okSuppliers = [];
        if ($suppliers !== []) {
            // Fornecedor é cadastro geral (sem condomínio): basta seguir ativo.
            $stmt = $this->pdo->prepare("SELECT id FROM erp_suppliers WHERE deleted_at IS NULL AND status = 'active'
                AND id IN (" . implode(',', array_fill(0, count($suppliers), '?')) . ')');
            $stmt->execute($suppliers);
            $okSuppliers = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $okDocuments = [];
        if ($documents !== []) {
            $stmt = $this->pdo->prepare('SELECT id FROM erp_documents WHERE deleted_at IS NULL AND condominium_id = ? AND id IN ('
                . implode(',', array_fill(0, count($documents), '?')) . ')');
            $stmt->execute(array_merge([$condominium], $documents));
            $okDocuments = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $ok = [];
        foreach ($rows as $r) {
            $supplier = (int)($r['supplier_id'] ?? 0);
            $document = (int)($r['document_id'] ?? 0);
            if (($supplier === 0 || isset($okSuppliers[$supplier])) && ($document === 0 || isset($okDocuments[$document]))) {
                $ok[(int)$r['id']] = true;
            }
        }
        return $ok;
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $days = $input['older_than_days'] ?? null;
        $days = $days === null || $days === '' ? self::DEFAULT_DAYS : $int($days);
        if ($days === false || $days < self::MIN_DAYS || $days > 3650) {
            throw new InvalidArgumentException(sprintf('Prazo: de %d a 3650 dias em aberto.', self::MIN_DAYS));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('commitment_ids', $input) && $input['commitment_ids'] !== null) {
            if (!is_array($input['commitment_ids']) || $input['commitment_ids'] === []) {
                throw new InvalidArgumentException('Empenhos: informe a lista de ids ou deixe vazio para todos os em aberto fora do prazo.');
            }
            $ids = [];
            foreach ($input['commitment_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Empenhos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_COMMITMENTS) {
                throw new InvalidArgumentException(sprintf('Até %d empenhos por vez.', self::MAX_COMMITMENTS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $today = new DateTimeImmutable('today');
        $cutoff = $today->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $fields = array_keys(RegistryService::definition('commitments')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'older_than_days' => (int)$days, 'committed_until' => $cutoff, 'reason' => $reason,
            'skipped_with_entry' => 0, 'skipped_inactive_link' => 0, 'commitments' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_commitments WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND committed_on <= ? ORDER BY committed_on, id LIMIT " . (self::MAX_COMMITMENTS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $cutoff]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_COMMITMENTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d empenhos fora do prazo: cancele em partes (prazo maior).', self::MAX_COMMITMENTS));
                }
                $withEntry = array_values(array_filter($rows, static fn (array $r): bool => $r['entry_id'] !== null));
                $result['skipped_with_entry'] = count($withEntry);
                $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['entry_id'] === null));
                $active = $this->activeLinks((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_link'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_commitments WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $active = $this->activeLinks((int)$condominium, array_values($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $cutoff, isset($active[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_commitment' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        $field === 'amount_cents' => number_format(((int)$value) / 100, 2, ',', ''),
                        in_array($field, ['committed_on', 'settled_on'], true) => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'commitments', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_commitment' => (string)$row['id']]);
                }
                $on = substr((string)$row['committed_on'], 0, 10);
                $result['commitments'][] = ['id' => (int)$row['id'], 'committed_on' => $on, 'days_open' => (int)(new DateTimeImmutable($on))->diff($today)->days,
                    'amount_cents' => (int)$row['amount_cents']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['commitments'] as $c) {
                Audit::record('cancel', 'erp_commitments', $c['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'committed_on' => $c['committed_on'], 'days_open' => $c['days_open'], 'amount_cents' => $c['amount_cents'],
                        'reason' => $reason, 'batch' => count($result['commitments'])]);
            }
        }
        $result['count'] = count($result['commitments']);
        $result['amount_cents'] = array_sum(array_column($result['commitments'], 'amount_cents'));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
