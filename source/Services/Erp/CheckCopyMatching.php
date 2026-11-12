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
 * Vínculo em lote das cópias de cheques aos lançamentos pagos (ADR 0249). O cadastro de cópias de cheques (AP-127) liga
 * cada cheque, opcionalmente, ao lançamento pago com ele; a conferência (ADR 0214) aponta os cheques emitidos sem
 * lançamento (no_entry), mas a ligação é cheque por cheque no formulário, procurando o lançamento na lista.
 *
 * Reusa o que já existe: cada cheque é regravado pelo RegistryService::save do cadastro "check_copies" só com o
 * lançamento (entry_id) preenchido; valem as regras e a auditoria update do formulário (conta bancária ativa, imagem no
 * Disco Virtual, lançamento ativo do mesmo condomínio); o valor vai em reais, como o formulário lê, e os demais campos
 * ficam como estão. O lançamento não é tocado. Nada é apagado e nada é enviado.
 *
 * Regra pura candidates() e pair(): o lançamento confere com o cheque quando é despesa ativa, paga pela mesma conta
 * bancária, com o mesmo valor, paga do dia do cheque até window_days depois (padrão 30, de 0 a 120) e ainda não ligada a
 * outra cópia de cheque. Só entra o par sem dúvida: o cheque com um único lançamento que confere e esse lançamento sem
 * outro cheque do lote que também confira. Os demais ficam como estão (skipped_no_match e skipped_ambiguous): ligue pelo
 * formulário.
 *
 * - Entram: os cheques emitidos e sem lançamento do condomínio, ou só os check_copy_ids informados. Regra pura problem():
 *   cheque não encontrado no condomínio, cancelado, já ligado a um lançamento, ou cuja conta bancária ficou inativa ou cuja
 *   imagem foi removida (o formulário não regrava: corrija no cadastro). Sem ids, esses últimos ficam de fora e a
 *   contagem sai em skipped_inactive_link; com ids, um cheque sem par ou com dúvida também recusa o lote.
 * - Tudo ou nada, numa transação com os cheques travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _check). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro link_entry por cheque (lançamento, valor, dias até o pagamento e
 *   motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, número, data e valor do cheque,
 * o id do lançamento, a data do pagamento e os dias até ele: nenhum favorecido (pode ser pessoa física), descrição ou
 * documento. Até MAX_CHECKS por vez; motivo obrigatório.
 */
final class CheckCopyMatching
{
    public const MAX_CHECKS = 500;
    public const DEFAULT_WINDOW = 30;
    public const MAX_WINDOW = 120;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do par (regra pura); null quando o cheque pode ser ligado. */
    public static function problem(?array $row, bool $linksActive = true): ?string
    {
        return match (true) {
            $row === null => 'Cheque não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O cheque está cancelado.',
            ($row['entry_id'] ?? null) !== null => 'O cheque já está ligado a um lançamento.',
            !$linksActive => 'A conta bancária do cheque está inativa ou a imagem foi removida: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Lançamentos que conferem com o cheque (regra pura).
     *
     * @param array{bank_account_id:int, amount_cents:int, issued_on:string} $check
     * @param list<array{id:int, bank_account_id:?int, amount_cents:int, paid_on:?string}> $entries despesas ativas ainda não ligadas
     * @return list<int>
     */
    public static function candidates(array $check, array $entries, int $window): array
    {
        $from = substr($check['issued_on'], 0, 10);
        $to = (new DateTimeImmutable($from))->modify("+{$window} days")->format('Y-m-d');
        $ids = [];
        foreach ($entries as $e) {
            $paid = substr((string)$e['paid_on'], 0, 10);
            if ($e['bank_account_id'] === $check['bank_account_id'] && $e['amount_cents'] === $check['amount_cents'] && $paid !== '' && $paid >= $from && $paid <= $to) {
                $ids[] = $e['id'];
            }
        }
        return $ids;
    }

    /**
     * Pares sem dúvida (regra pura): cheque com um único candidato e candidato de um único cheque.
     *
     * @param array<int, array{bank_account_id:int, amount_cents:int, issued_on:string}> $checks por id
     * @param list<array{id:int, bank_account_id:?int, amount_cents:int, paid_on:?string}> $entries
     * @return array{pairs: array<int, int>, no_match: list<int>, ambiguous: list<int>}
     */
    public static function pair(array $checks, array $entries, int $window): array
    {
        $found = [];
        $claims = [];
        foreach ($checks as $id => $check) {
            $found[$id] = self::candidates($check, $entries, $window);
            foreach ($found[$id] as $entry) {
                $claims[$entry] = ($claims[$entry] ?? 0) + 1;
            }
        }
        $result = ['pairs' => [], 'no_match' => [], 'ambiguous' => []];
        foreach ($found as $id => $list) {
            if ($list === []) {
                $result['no_match'][] = $id;
            } elseif (count($list) > 1 || $claims[$list[0]] > 1) {
                $result['ambiguous'][] = $id;
            } else {
                $result['pairs'][$id] = $list[0];
            }
        }
        return $result;
    }

    /**
     * Ids dos cheques cuja conta bancária segue ativa e cuja imagem segue no Disco Virtual do condomínio: o formulário só
     * regrava esses.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function activeLinks(int $condominium, array $rows): array
    {
        $banks = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['bank_account_id'] ?? 0), $rows))));
        $documents = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['document_id'] ?? 0), $rows))));
        $okBanks = [];
        if ($banks !== []) {
            $stmt = $this->pdo->prepare("SELECT id FROM erp_bank_accounts WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND id IN ("
                . implode(',', array_fill(0, count($banks), '?')) . ')');
            $stmt->execute(array_merge([$condominium], $banks));
            $okBanks = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
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
            if (isset($okBanks[(int)$r['bank_account_id']]) && isset($okDocuments[(int)($r['document_id'] ?? 0)])) {
                $ok[(int)$r['id']] = true;
            }
        }
        return $ok;
    }

    /** @param array<string, mixed> $input */
    public function link(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $window = $input['window_days'] ?? self::DEFAULT_WINDOW;
        $window = is_int($window) || is_string($window) ? filter_var($window, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_WINDOW]]) : false;
        if ($window === false) {
            throw new InvalidArgumentException(sprintf('Prazo até o pagamento: de 0 a %d dias.', self::MAX_WINDOW));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('check_copy_ids', $input) && $input['check_copy_ids'] !== null) {
            if (!is_array($input['check_copy_ids']) || $input['check_copy_ids'] === []) {
                throw new InvalidArgumentException('Cheques: informe a lista de ids ou deixe vazio para todos os sem lançamento.');
            }
            $ids = [];
            foreach ($input['check_copy_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Cheques: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_CHECKS) {
                throw new InvalidArgumentException(sprintf('Até %d cheques por vez.', self::MAX_CHECKS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('check_copies')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'window_days' => (int)$window, 'reason' => $reason,
            'skipped_inactive_link' => 0, 'skipped_no_match' => 0, 'skipped_ambiguous' => 0, 'checks' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_check_copies WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND entry_id IS NULL ORDER BY issued_on, id LIMIT " . (self::MAX_CHECKS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_CHECKS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d cheques sem lançamento: ligue em partes, escolhendo os ids.', self::MAX_CHECKS));
                }
                $active = $this->activeLinks((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_link'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_check_copies WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $active = $this->activeLinks((int)$condominium, array_values($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, isset($active[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_check' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $checks = [];
            foreach ($rows as $r) {
                $checks[(int)$r['id']] = ['bank_account_id' => (int)$r['bank_account_id'], 'amount_cents' => (int)$r['amount_cents'],
                    'issued_on' => substr((string)$r['issued_on'], 0, 10)];
            }
            $entries = [];
            if ($checks !== []) {
                // Despesas ativas pagas pelas contas dos cheques e ainda não ligadas a outra cópia de cheque (qualquer situação).
                $banks = array_values(array_unique(array_column($checks, 'bank_account_id')));
                $stmt = $this->pdo->prepare("SELECT e.id, e.bank_account_id, e.amount_cents, e.paid_on FROM erp_entries e
                    WHERE e.condominium_id = ? AND e.deleted_at IS NULL AND e.status = 'active' AND e.nature = 'despesa' AND e.paid_on IS NOT NULL
                    AND e.paid_on >= ? AND e.bank_account_id IN (" . implode(',', array_fill(0, count($banks), '?')) . ")
                    AND NOT EXISTS (SELECT 1 FROM erp_check_copies k WHERE k.entry_id = e.id AND k.deleted_at IS NULL)");
                $stmt->execute(array_merge([(int)$condominium, min(array_column($checks, 'issued_on'))], $banks));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
                    $entries[] = ['id' => (int)$e['id'], 'bank_account_id' => $e['bank_account_id'] !== null ? (int)$e['bank_account_id'] : null,
                        'amount_cents' => (int)$e['amount_cents'], 'paid_on' => substr((string)$e['paid_on'], 0, 10)];
                }
            }
            $paired = self::pair($checks, $entries, (int)$window);
            if ($ids !== null) {
                foreach ($ids as $id) {
                    if (in_array($id, $paired['no_match'], true)) {
                        throw new RegistryValidationException(['_form' => 'Nenhum lançamento pago confere com o cheque (conta, valor e prazo): ligue pelo formulário.',
                            '_check' => (string)$id]);
                    }
                    if (in_array($id, $paired['ambiguous'], true)) {
                        throw new RegistryValidationException(['_form' => 'Mais de um lançamento confere com o cheque: ligue pelo formulário.', '_check' => (string)$id]);
                    }
                }
            }
            $result['skipped_no_match'] = count($paired['no_match']);
            $result['skipped_ambiguous'] = count($paired['ambiguous']);
            $paidOn = array_column($entries, 'paid_on', 'id');
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if (!isset($paired['pairs'][$id])) {
                    continue;
                }
                $entry = $paired['pairs'][$id];
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        $field === 'amount_cents' => number_format((int)$value / 100, 2, ',', ''),   // o formulário lê valor em reais
                        $field === 'issued_on' => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'check_copies', array_merge($data, ['entry_id' => (string)$entry]), $id);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_check' => (string)$id]);
                }
                $issued = substr((string)$row['issued_on'], 0, 10);
                $result['checks'][] = ['id' => $id, 'check_number' => (int)$row['check_number'], 'issued_on' => $issued, 'amount_cents' => (int)$row['amount_cents'],
                    'entry_id' => $entry, 'paid_on' => $paidOn[$entry], 'days_to_payment' => (int)(new DateTimeImmutable($issued))->diff(new DateTimeImmutable($paidOn[$entry]))->days];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['checks'] as $c) {
                Audit::record('link_entry', 'erp_check_copies', $c['id'], ['entry_id' => null],
                    ['entry_id' => $c['entry_id'], 'amount_cents' => $c['amount_cents'], 'days_to_payment' => $c['days_to_payment'], 'window_days' => (int)$window,
                        'reason' => $reason, 'batch' => count($result['checks'])]);
            }
        }
        $result['count'] = count($result['checks']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
