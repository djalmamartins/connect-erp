<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Encerramento em lote dos processos de cobrança com as contas quitadas (ADR 0251). A cobrança (Collections) encerra o
 * processo pelo andamento, um a um (addEvent com to_status = closed e o motivo); a carteira (ADR 0250) mostra quanto das
 * contas cobradas já foi pago, mas o processo cujo devedor pagou tudo segue "Ajuizado" ou "Suspenso" até alguém abrir o
 * processo e registrar o encerramento.
 *
 * Reusa o que já existe: a regra de transição Collections::canTransition (encerrar é aceito de qualquer fase aberta) e o
 * mesmo encerramento de Collections::addEvent, feito aqui dentro de uma transação só para o lote (addEvent abre a sua): a
 * fase vai para "Encerrado", closed_on e close_reason são gravados e um andamento "closed" é registrado com a nota. Nada é
 * apagado e nada é enviado; as contas não são tocadas.
 *
 * - Quitado (regra pura settled()): o processo tem contas ligadas e todas estão ativas e pagas. Conta em aberto, conta
 *   cancelada ou excluída (por exemplo, trocada por acordo) ou processo sem contas ficam de fora: encerre pelo andamento.
 * - Data do encerramento: o pagamento da última conta, nunca antes da abertura nem depois de hoje.
 * - Entram: os processos não encerrados do condomínio, ou só os case_ids informados. Regra pura problem(): processo não
 *   encontrado no condomínio, já encerrado, sem contas, com conta em aberto ou com conta cancelada. Sem ids, os que não
 *   estão quitados ficam de fora e as contagens saem em skipped_open, skipped_cancelled e skipped_no_entries.
 * - Tudo ou nada, numa transação com os processos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _case). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro close_settled por processo (fase anterior, data do encerramento, valor recuperado, contas e
 *   motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, fase anterior, data do
 * encerramento, valor recuperado e quantidade de contas: nenhuma unidade, devedor, advogado, número do processo ou vara.
 * Até MAX_CASES por vez; motivo obrigatório.
 */
final class LegalCaseSettlement
{
    public const MAX_CASES = 500;
    public const NOTE_PREFIX = 'Encerrado em lote: todas as contas cobradas foram pagas. ';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Situação das contas do processo (regra pura): settled, open, cancelled ou no_entries.
     *
     * @param array{total:int, paid:int, open:int, cancelled:int} $entries
     */
    public static function settled(array $entries): string
    {
        return match (true) {
            $entries['total'] === 0 => 'no_entries',
            $entries['open'] > 0 => 'open',
            $entries['cancelled'] > 0 || $entries['paid'] !== $entries['total'] => 'cancelled',
            default => 'settled',
        };
    }

    /** Motivo da recusa (regra pura); null quando o processo pode ser encerrado. */
    public static function problem(?array $case, array $entries): ?string
    {
        if ($case === null) {
            return 'Processo não encontrado neste condomínio.';
        }
        if (($case['status'] ?? '') === 'closed' || !Collections::canTransition((string)$case['status'], 'closed')) {
            return 'O processo já está encerrado.';
        }
        return match (self::settled($entries)) {
            'no_entries' => 'O processo não tem contas ligadas: encerre pelo andamento.',
            'open' => 'Ainda há conta cobrada em aberto no processo.',
            'cancelled' => 'Há conta cobrada cancelada ou excluída (acordo?): encerre pelo andamento.',
            default => null,
        };
    }

    /** Data do encerramento (regra pura): o último pagamento, entre a abertura e hoje. */
    public static function closingDate(string $openedOn, ?string $lastPaidOn, string $today): string
    {
        $date = substr((string)$lastPaidOn, 0, 10);
        $opened = substr($openedOn, 0, 10);
        if ($date === '' || $date < $opened) {
            $date = $opened;
        }
        return min($date, $today);
    }

    /** @param array<string, mixed> $input */
    public function close(User $user, array $input): array
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
        if (array_key_exists('case_ids', $input) && $input['case_ids'] !== null) {
            if (!is_array($input['case_ids']) || $input['case_ids'] === []) {
                throw new InvalidArgumentException('Processos: informe a lista de ids ou deixe vazio para todos os quitados.');
            }
            $ids = [];
            foreach ($input['case_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Processos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_CASES) {
                throw new InvalidArgumentException(sprintf('Até %d processos por vez.', self::MAX_CASES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $today = date('Y-m-d');
        $note = self::NOTE_PREFIX . $reason;
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'skipped_open' => 0, 'skipped_cancelled' => 0,
            'skipped_no_entries' => 0, 'recovered_cents' => 0, 'cases' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT id, status, opened_on FROM erp_legal_cases WHERE condominium_id = ? AND status <> 'closed'
                    ORDER BY opened_on, id LIMIT " . (self::MAX_CASES + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_CASES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d processos abertos: encerre em partes, escolhendo os ids.', self::MAX_CASES));
                }
            } else {
                $stmt = $this->pdo->prepare('SELECT id, status, opened_on FROM erp_legal_cases WHERE condominium_id = ? AND id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $rows = [];
                foreach ($ids as $id) {
                    $rows[] = $byId[$id] ?? ['id' => $id, 'missing' => true];
                }
            }
            $caseIds = array_values(array_map(static fn (array $r): int => (int)$r['id'], array_filter($rows, static fn (array $r): bool => !isset($r['missing']))));
            $entries = [];
            if ($caseIds !== []) {
                $stmt = $this->pdo->prepare("SELECT le.case_id, COUNT(*) AS total,
                        SUM(CASE WHEN e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NOT NULL THEN 1 ELSE 0 END) AS paid,
                        SUM(CASE WHEN e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NULL THEN 1 ELSE 0 END) AS open,
                        SUM(CASE WHEN e.deleted_at IS NOT NULL OR e.status <> 'active' THEN 1 ELSE 0 END) AS cancelled,
                        SUM(CASE WHEN e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NOT NULL THEN le.amount_cents ELSE 0 END) AS recovered,
                        MAX(CASE WHEN e.deleted_at IS NULL AND e.status = 'active' THEN e.paid_on END) AS last_paid
                    FROM erp_legal_case_entries le JOIN erp_entries e ON e.id = le.entry_id
                    WHERE le.case_id IN (" . implode(',', array_fill(0, count($caseIds), '?')) . ') GROUP BY le.case_id');
                $stmt->execute($caseIds);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $entries[(int)$s['case_id']] = ['total' => (int)$s['total'], 'paid' => (int)$s['paid'], 'open' => (int)$s['open'], 'cancelled' => (int)$s['cancelled'],
                        'recovered' => (int)$s['recovered'], 'last_paid' => $s['last_paid'] !== null ? substr((string)$s['last_paid'], 0, 10) : null];
                }
            }
            $empty = ['total' => 0, 'paid' => 0, 'open' => 0, 'cancelled' => 0, 'recovered' => 0, 'last_paid' => null];
            $close = $this->pdo->prepare("UPDATE erp_legal_cases SET status = 'closed', closed_on = ?, close_reason = ? WHERE id = ? AND status <> 'closed'");
            $event = $this->pdo->prepare("INSERT INTO erp_legal_case_events (case_id, kind, from_status, to_status, note, happened_on, created_by)
                VALUES (?, 'closed', ?, 'closed', ?, ?, ?)");
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $summary = $entries[$id] ?? $empty;
                $case = isset($row['missing']) ? null : $row;
                if ($ids !== null) {
                    $problem = self::problem($case, $summary);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_case' => (string)$id]);
                    }
                } else {
                    $state = self::settled($summary);
                    if ($state !== 'settled') {
                        $result[match ($state) {
                            'open' => 'skipped_open',
                            'cancelled' => 'skipped_cancelled',
                            default => 'skipped_no_entries',
                        }]++;
                        continue;
                    }
                }
                $closedOn = self::closingDate((string)$row['opened_on'], $summary['last_paid'], $today);
                $close->execute([$closedOn, mb_substr($note, 0, 255), $id]);
                $event->execute([$id, (string)$row['status'], mb_substr($note, 0, 1000), $closedOn, (int)$user->id]);
                $result['recovered_cents'] += $summary['recovered'];
                $result['cases'][] = ['id' => $id, 'from_status' => (string)$row['status'], 'closed_on' => $closedOn, 'recovered_cents' => $summary['recovered'],
                    'entries' => $summary['total']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['cases'] as $c) {
                Audit::record('close_settled', 'erp_legal_cases', $c['id'], ['status' => $c['from_status']],
                    ['status' => 'closed', 'closed_on' => $c['closed_on'], 'recovered_cents' => $c['recovered_cents'], 'entries' => $c['entries'],
                        'reason' => $reason, 'batch' => count($result['cases'])]);
            }
        }
        $result['count'] = count($result['cases']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
