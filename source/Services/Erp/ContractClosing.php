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
 * Encerramento em lote dos contratos com a vigência terminada (ADR 0245). O cadastro de contratos (AP-119) tem a situação
 * "Encerrado", mas ela só muda quando alguém abre o formulário: o contrato de portaria trocado em junho segue "Vigente",
 * entra no painel operacional como vencido (contracts_expired) e no calendário de obrigações, e soma no valor mensal
 * contratado. A renovação em lote (ADR 0217) estende os que continuam; esta ação fecha os que acabaram.
 *
 * Reusa o que já existe: cada contrato é regravado pelo RegistryService::save do cadastro "contracts" só com a situação
 * "Encerrado" (valem as regras e a auditoria update do formulário); o fim, o valor e os demais campos ficam como estão. A
 * checagem do fornecedor ativo e do documento no Disco Virtual é a mesma do cancelamento de empenhos (ADR 0239). Nada é
 * apagado e nada é enviado.
 *
 * - Entram: os contratos vigentes do condomínio com fim anterior a before (padrão hoje; nunca depois de hoje), ou só os
 *   contract_ids informados. Regra pura problem(): contrato não encontrado no condomínio, encerrado ou cancelado, de prazo
 *   indeterminado (sem fim), com o fim ainda por vir (o próprio dia vale) ou cujo fornecedor ficou inativo ou cujo
 *   documento foi removido (o formulário não regrava: corrija no cadastro). Sem ids, esses últimos ficam de fora e a
 *   contagem sai em skipped_inactive_link.
 * - Tudo ou nada, numa transação com os contratos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _contract). dry_run passa por tudo e desfaz.
 * - monthly_cents soma o valor mensal dos encerrados. recurring_to_review conta os lançamentos fixos vigentes do
 *   condomínio com o fornecedor de um contrato encerrado (sem fim ou com a última competência a partir do mês atual):
 *   eles não são tocados, só apontados para revisão no reajuste e na geração do mês (ADR 0204).
 * - Auditoria: a update do formulário e um registro close por contrato (fim, dias desde o fim, valor mensal e motivo), só
 *   quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, fim, dias desde o fim e valor
 * mensal: nenhum objeto, fornecedor, documento ou observação. Até MAX_CONTRACTS por vez; motivo obrigatório.
 */
final class ContractClosing
{
    public const MAX_CONTRACTS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o contrato pode ser encerrado. */
    public static function problem(?array $row, string $before, bool $linksActive = true): ?string
    {
        $ends = substr((string)($row['ends_on'] ?? ''), 0, 10);
        return match (true) {
            $row === null => 'Contrato não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O contrato já está encerrado ou cancelado.',
            $ends === '' => 'Contrato de prazo indeterminado: não há fim para encerrar.',
            $ends >= $before => 'A vigência do contrato ainda não terminou.',
            !$linksActive => 'O fornecedor do contrato está inativo ou o documento foi removido: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Ids dos contratos cujo fornecedor segue ativo e cujo documento (se houver) segue no Disco Virtual do condomínio: o
     * formulário só regrava esses (a mesma checagem da ADR 0239).
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
    public function close(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today = date('Y-m-d');
        $before = $input['before'] ?? null;
        $before = $before === null || $before === '' ? $today : $before;
        $date = is_string($before) ? DateTimeImmutable::createFromFormat('!Y-m-d', $before) : false;
        if ($date === false || $date->format('Y-m-d') !== $before || $before > $today) {
            throw new InvalidArgumentException('Data de corte: AAAA-MM-DD, até hoje.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('contract_ids', $input) && $input['contract_ids'] !== null) {
            if (!is_array($input['contract_ids']) || $input['contract_ids'] === []) {
                throw new InvalidArgumentException('Contratos: informe a lista de ids ou deixe vazio para todos os terminados.');
            }
            $ids = [];
            foreach ($input['contract_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Contratos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_CONTRACTS) {
                throw new InvalidArgumentException(sprintf('Até %d contratos por vez.', self::MAX_CONTRACTS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('contracts')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'reason' => $reason, 'skipped_inactive_link' => 0,
            'monthly_cents' => 0, 'recurring_to_review' => 0, 'contracts' => []];
        $suppliers = [];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_contracts WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND ends_on IS NOT NULL AND ends_on < ? ORDER BY ends_on, id LIMIT " . (self::MAX_CONTRACTS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $before]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_CONTRACTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d contratos terminados: encerre em partes (data de corte menor).', self::MAX_CONTRACTS));
                }
                $active = $this->activeLinks((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_link'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_contracts WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $active = $this->activeLinks((int)$condominium, array_values($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $before, isset($active[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_contract' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $today0 = new DateTimeImmutable($today);
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        $field === 'monthly_cents' => number_format((int)$value / 100, 2, ',', ''),   // o formulário lê valor em reais
                        in_array($field, ['starts_on', 'ends_on'], true) => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'contracts', array_merge($data, ['status' => 'ended']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_contract' => (string)$row['id']]);
                }
                $ends = substr((string)$row['ends_on'], 0, 10);
                $monthly = (int)$row['monthly_cents'];
                $suppliers[(int)$row['supplier_id']] = (int)$row['supplier_id'];
                $result['monthly_cents'] += $monthly;
                $result['contracts'][] = ['id' => (int)$row['id'], 'ends_on' => $ends, 'days_since_end' => (int)(new DateTimeImmutable($ends))->diff($today0)->days,
                    'monthly_cents' => $monthly];
            }
            $suppliers = array_values(array_filter($suppliers));
            if ($suppliers !== []) {
                $recurring = $this->pdo->prepare("SELECT COUNT(*) FROM erp_recurring_entries WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND (ends_competence IS NULL OR ends_competence >= ?) AND supplier_id IN (" . implode(',', array_fill(0, count($suppliers), '?')) . ')');
                $recurring->execute(array_merge([(int)$condominium, substr($today, 0, 7)], $suppliers));
                $result['recurring_to_review'] = (int)$recurring->fetchColumn();
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['contracts'] as $c) {
                Audit::record('close', 'erp_contracts', $c['id'], ['status' => 'active'],
                    ['status' => 'ended', 'ends_on' => $c['ends_on'], 'days_since_end' => $c['days_since_end'], 'monthly_cents' => $c['monthly_cents'],
                        'reason' => $reason, 'batch' => count($result['contracts'])]);
            }
        }
        $result['count'] = count($result['contracts']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
