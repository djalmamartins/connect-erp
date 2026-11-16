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
 * Contas a receber ainda ativas de faturas canceladas inativadas em lote (ADR 0401). A conferência das faturas (ADR 0400)
 * aponta como still_active o item de fatura cancelada cuja conta segue ativa: Invoices::cancel inativa as contas da fatura
 * emitida, mas uma conta reativada depois pelo cadastro, ou gravada fora do fluxo, fica cobrando a unidade por uma fatura
 * que não vale mais. Corrigir era uma por uma, pelo cadastro de lançamentos.
 *
 * Reusa o que já existe: a mesma gravação de Invoices::cancel (status inactive e updated_by na conta ligada ao item pelo
 * invoice_item_id), as mesmas recusas do cancelamento (conta paga; conta em acordo, agreement_id e erp_agreement_originals;
 * competência fechada, RegistryService::isClosed, que trava a leitura do fechamento dentro da transação) e o cadastro de
 * lançamentos (RegistryService::definition('entries'): a tabela e a permissão). Nada é apagado.
 *
 * Só as contas que ninguém mais usa: além de paga, em acordo e de competência fechada, fica de fora a conta ligada a um
 * boleto (erp_bank_charges, qualquer situação), a uma cobrança judicial (erp_legal_case_entries), a um documento da
 * unidade (erp_unit_document_entries), a uma linha de extrato (erp_bank_statement_lines) ou a uma cópia de cheque
 * (erp_check_copies). Essas tabelas só são lidas para saber se há ligação: nenhum boleto, extrato, credencial ou
 * integração bancária é lido além disso, gravado ou chamado.
 *
 * - Entram: sem entry_ids, as contas ativas, não excluídas, de itens de faturas canceladas do condomínio que não têm
 *   recusa; as que têm são contadas em skipped_paid, skipped_linked (acordo ou ligação) e skipped_closed. Com entry_ids,
 *   só as informadas. Regra pura problem(): não encontrada entre as contas de faturas canceladas do condomínio, já
 *   inativa, paga, em acordo ou ligada, ou de competência fechada.
 * - Tudo ou nada, numa transação com as contas travadas (FOR UPDATE). Uma recusada desfaz todas
 *   (RegistryValidationException com _form e _entry). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro update por conta (status, fatura, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids de contas e de faturas e a
 * competência: nenhuma unidade, morador, descrição, valor nem dado bancário. Até MAX_ENTRIES por vez; motivo obrigatório.
 */
final class InvoiceEntryCleanup
{
    public const MAX_ENTRIES = 500;

    /** Tabelas que, ligadas à conta, impedem a inativação (coluna entry_id). */
    public const LINKS = ['erp_agreement_originals', 'erp_bank_charges', 'erp_bank_charge_entries', 'erp_legal_case_entries', 'erp_unit_document_entries', 'erp_bank_statement_lines',
        'erp_check_copies'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?RegistryService $registry = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = $registry ?? new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Motivo da recusa (regra pura); null quando a conta pode ser inativada.
     *
     * @param array{status:string, paid:bool, agreement:bool}|null $row
     */
    public static function problem(?array $row, bool $linked, bool $closed): ?string
    {
        return match (true) {
            $row === null => 'Conta não encontrada entre as contas de faturas canceladas deste condomínio.',
            $row['status'] !== 'active' => 'A conta já está inativa.',
            $row['paid'] => 'A conta já foi paga: estorne a baixa antes.',
            $row['agreement'] || $linked => 'A conta está ligada a acordo, boleto, cobrança judicial, documento, extrato ou cheque.',
            $closed => 'A competência da conta está fechada. Reabra o fechamento mensal antes.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function deactivate(User $user, array $input): array
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
        $ids = null;
        if (array_key_exists('entry_ids', $input) && $input['entry_ids'] !== null) {
            if (!is_array($input['entry_ids']) || $input['entry_ids'] === []) {
                throw new InvalidArgumentException('Contas: informe a lista de ids ou deixe vazio para todas as de faturas canceladas.');
            }
            $ids = [];
            foreach ($input['entry_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Contas: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_ENTRIES) {
                throw new InvalidArgumentException(sprintf('Até %d contas por vez.', self::MAX_ENTRIES));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        $entries = (string)RegistryService::definition('entries')['table'];

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'entries' => [], 'skipped_paid' => 0, 'skipped_linked' => 0, 'skipped_closed' => 0];
        $this->pdo->beginTransaction();
        try {
            // Só contas de itens de faturas canceladas do próprio condomínio.
            $sql = "SELECT e.id, e.status, e.competence, it.invoice_id, CASE WHEN e.paid_on IS NULL THEN 0 ELSE 1 END AS paid,
                    CASE WHEN e.agreement_id IS NULL THEN 0 ELSE 1 END AS agreement
                FROM {$entries} e JOIN erp_invoice_items it ON it.id = e.invoice_item_id JOIN erp_invoices i ON i.id = it.invoice_id
                WHERE i.condominium_id = ? AND i.status = 'cancelled' AND e.condominium_id = i.condominium_id AND e.deleted_at IS NULL";
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND e.status = 'active'";
            } else {
                $sql .= ' AND e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY e.id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'status' => (string)$r['status'], 'competence' => (string)$r['competence'],
                    'invoice_id' => (int)$r['invoice_id'], 'paid' => (bool)$r['paid'], 'agreement' => (bool)$r['agreement']];
            }
            $linked = [];
            if ($byId !== []) {
                $in = implode(',', array_fill(0, count($byId), '?'));
                foreach (self::LINKS as $table) {
                    $stmt = $this->pdo->prepare("SELECT DISTINCT entry_id FROM {$table} WHERE entry_id IN ({$in})");
                    $stmt->execute(array_keys($byId));
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
                        $linked[(int)$entryId] = true;
                    }
                }
            }
            $closed = [];
            foreach (array_unique(array_column($byId, 'competence')) as $competence) {
                $closed[$competence] = $this->registry->isClosed((int)$condominium, $competence);
            }
            $check = static fn (?array $row): ?string => self::problem($row, $row !== null && isset($linked[$row['id']]), $row !== null && ($closed[$row['competence']] ?? false));
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $row) {
                    $problem = $check($row);
                    if ($problem === null) {
                        $rows[] = $row;
                    } elseif ($row['paid']) {
                        $result['skipped_paid']++;
                    } elseif ($row['agreement'] || isset($linked[$row['id']])) {
                        $result['skipped_linked']++;
                    } else {
                        $result['skipped_closed']++;
                    }
                }
                if (count($rows) > self::MAX_ENTRIES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d contas de faturas canceladas: inative em partes (escolha os ids).', self::MAX_ENTRIES));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = $check($byId[$id] ?? null);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_entry' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            // A mesma gravação de Invoices::cancel, com as condições conferidas de novo no UPDATE.
            $update = $this->pdo->prepare("UPDATE {$entries} SET status = 'inactive', updated_by = ? WHERE id = ? AND condominium_id = ? AND status = 'active'
                AND deleted_at IS NULL AND paid_on IS NULL AND agreement_id IS NULL");
            foreach ($rows as $row) {
                $update->execute([(int)$user->id, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Uma conta mudou de situação enquanto a inativação era pedida.');
                }
                $result['entries'][] = ['id' => $row['id'], 'invoice_id' => $row['invoice_id'], 'competence' => $row['competence']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['entries'] as $e) {
                Audit::record('update', $entries, $e['id'], ['status' => 'active'], ['status' => 'inactive', 'invoice_id' => $e['invoice_id'],
                    'reason' => $reason, 'batch' => count($result['entries'])]);
            }
        }
        $result['invoices'] = count(array_unique(array_column($result['entries'], 'invoice_id')));
        $result['count'] = count($result['entries']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
