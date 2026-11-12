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
 * Liquidação em lote dos empenhos com o lançamento já pago (ADR 0253). A posição dos empenhos (ADR 0238) mostra quanto
 * segue empenhado; o cancelamento em lote (ADR 0239) deixa de fora os empenhos com lançamento ligado, porque o pagamento
 * aconteceu e o certo é liquidar no cadastro. Hoje cada um é liquidado à mão no formulário (AP-128), copiando a data do
 * pagamento, e o valor empenhado fica inflado até alguém lembrar.
 *
 * Reusa o que já existe: cada empenho é regravado pelo RegistryService::save do cadastro "commitments" com a situação
 * "Liquidado" e settled_on = paid_on do lançamento ligado (valem as regras, as referências e a auditoria update do
 * formulário), no mesmo desenho do cancelamento em lote (ADR 0239). O lançamento não é alterado. Nada é apagado e nada é
 * enviado.
 *
 * - Entram: os empenhos em aberto do condomínio com lançamento ligado e pago, ou só os commitment_ids informados.
 *   Regra pura problem(): empenho não encontrado no condomínio, que não está em aberto, sem lançamento ligado, com o
 *   lançamento cancelado, removido ou de outro condomínio, com o lançamento ainda não pago, pago antes da data do empenho
 *   (o formulário não aceita), ou cujo fornecedor ficou inativo ou cujo documento foi removido (corrija no cadastro).
 *   Sem ids: os de lançamento em aberto ficam de fora e a contagem sai em awaiting_payment; os de lançamento inválido
 *   (cancelado, removido ou pago antes do empenho), em skipped_entry; os de fornecedor inativo ou documento removido, em
 *   skipped_inactive_link.
 * - Tudo ou nada, numa transação com os empenhos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _commitment). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro settle por empenho (data, lançamento, valores), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, datas, o id do lançamento e
 * os valores: nenhuma descrição, fornecedor, documento ou observação. Até MAX_COMMITMENTS por vez.
 */
final class CommitmentSettlement
{
    public const MAX_COMMITMENTS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Motivo da recusa antes do formulário (regra pura); null quando o empenho pode ser liquidado.
     * $entry = o lançamento ligado válido (do condomínio, não cancelado, não removido) ou null.
     */
    public static function problem(?array $row, ?array $entry, bool $linksActive = true): ?string
    {
        return match (true) {
            $row === null => 'Empenho não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O empenho não está em aberto (já foi liquidado ou cancelado).',
            ($row['entry_id'] ?? null) === null => 'O empenho não tem lançamento ligado: ligue o lançamento no cadastro.',
            $entry === null => 'O lançamento ligado foi cancelado, removido ou é de outro condomínio: corrija no cadastro.',
            ($entry['paid_on'] ?? null) === null => 'O lançamento ligado ainda não foi pago.',
            substr((string)$entry['paid_on'], 0, 10) < substr((string)($row['committed_on'] ?? ''), 0, 10)
                => 'O lançamento foi pago antes da data do empenho: corrija no cadastro.',
            !$linksActive => 'O fornecedor do empenho está inativo ou o documento foi removido: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Lançamentos ligados válidos (do condomínio, não cancelados, não removidos), por id.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array{paid_on:?string, amount_cents:int}>
     */
    private function entries(int $condominium, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['entry_id'] ?? 0), $rows))));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT id, paid_on, amount_cents FROM erp_entries WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute(array_merge([$condominium], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $out[(int)$e['id']] = ['paid_on' => $e['paid_on'] === null ? null : substr((string)$e['paid_on'], 0, 10), 'amount_cents' => (int)$e['amount_cents']];
        }
        return $out;
    }

    /**
     * Ids dos empenhos cujo fornecedor (se houver) segue ativo e cujo documento (se houver) segue no Disco Virtual do
     * condomínio: o formulário só regrava esses (mesma regra do cancelamento em lote, ADR 0239).
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
    public function settle(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $ids = null;
        if (array_key_exists('commitment_ids', $input) && $input['commitment_ids'] !== null) {
            if (!is_array($input['commitment_ids']) || $input['commitment_ids'] === []) {
                throw new InvalidArgumentException('Empenhos: informe a lista de ids ou deixe vazio para todos os com lançamento pago.');
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

        $fields = array_keys(RegistryService::definition('commitments')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'awaiting_payment' => 0, 'skipped_entry' => 0, 'skipped_inactive_link' => 0,
            'commitments' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_commitments WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND entry_id IS NOT NULL ORDER BY committed_on, id LIMIT " . (self::MAX_COMMITMENTS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_COMMITMENTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d empenhos com lançamento ligado: liquide em partes (escolha os ids).', self::MAX_COMMITMENTS));
                }
                $entries = $this->entries((int)$condominium, $rows);
                $active = $this->activeLinks((int)$condominium, $rows);
                $kept = [];
                foreach ($rows as $r) {
                    $entry = $entries[(int)$r['entry_id']] ?? null;
                    if (self::problem($r, $entry, isset($active[(int)$r['id']])) === null) {
                        $kept[] = $r;
                    } elseif ($entry !== null && $entry['paid_on'] === null) {
                        $result['awaiting_payment']++;
                    } elseif ($entry === null || $entry['paid_on'] < substr((string)$r['committed_on'], 0, 10)) {
                        $result['skipped_entry']++;
                    } else {
                        $result['skipped_inactive_link']++;
                    }
                }
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_commitments WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $entries = $this->entries((int)$condominium, array_values($byId));
                $active = $this->activeLinks((int)$condominium, array_values($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $problem = self::problem($row, $row === null ? null : ($entries[(int)($row['entry_id'] ?? 0)] ?? null), isset($active[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_commitment' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                $entry = $entries[(int)$row['entry_id']];
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
                    $registry->save($user, 'commitments', array_merge($data, ['status' => 'settled', 'settled_on' => $entry['paid_on']]), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_commitment' => (string)$row['id']]);
                }
                $result['commitments'][] = ['id' => (int)$row['id'], 'committed_on' => substr((string)$row['committed_on'], 0, 10), 'settled_on' => $entry['paid_on'],
                    'entry_id' => (int)$row['entry_id'], 'amount_cents' => (int)$row['amount_cents'], 'entry_amount_cents' => $entry['amount_cents']];
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
                Audit::record('settle', 'erp_commitments', $c['id'], ['status' => 'active', 'settled_on' => null],
                    ['status' => 'settled', 'settled_on' => $c['settled_on'], 'entry_id' => $c['entry_id'], 'amount_cents' => $c['amount_cents'],
                        'entry_amount_cents' => $c['entry_amount_cents'], 'batch' => count($result['commitments'])]);
            }
        }
        $result['count'] = count($result['commitments']);
        $result['amount_cents'] = array_sum(array_column($result['commitments'], 'amount_cents'));
        $result['entry_amount_cents'] = array_sum(array_column($result['commitments'], 'entry_amount_cents'));
        $result['difference_cents'] = $result['entry_amount_cents'] - $result['amount_cents'];
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
