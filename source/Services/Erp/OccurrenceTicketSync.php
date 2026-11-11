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
use Source\Services\Support\TicketService;
use Source\Support\Audit;

/**
 * Ocorrências com o chamado do Help Desk encerrado (ADR 0213): a ocorrência que virou chamado (Occurrences::openTicket,
 * ADR 0034/0051) fica "Em andamento" no ERP para sempre, mesmo depois que o Help Desk fecha o chamado; nada traz o
 * desfecho de volta. A lista de ocorrências abertas, o painel e o resumo do síndico (ADR 0210) ficam inflados.
 *
 * Reusa o que já existe, sem consulta paralela ao Help Desk:
 * - TicketService::findFor: a situação do chamado, com a mesma regra de escopo do ERP (chamado do condomínio do usuário);
 *   só o protocolo e a situação são usados (nada do solicitante);
 * - RegistryService::save do cadastro "occurrences": a ocorrência é regravada só com a situação "Resolvida" (valem as
 *   regras e a auditoria do formulário).
 *
 * - Entram: ocorrências abertas ou em andamento do condomínio, com chamado, cujo chamado está fechado (closed); com
 *   include_resolved, também o resolvido (resolved), que ainda pode ser reaberto pelo solicitante. As outras ficam como
 *   estão e vêm contadas pela situação do chamado (still_open). Chamado fora do escopo ou inexistente: unavailable.
 * - Tudo ou nada, numa transação com as ocorrências travadas (FOR UPDATE): uma recusada pelo formulário desfaz todas
 *   (RegistryValidationException com _occurrence). dry_run passa por tudo e desfaz no fim. Auditoria: a update do
 *   formulário e um registro ticket_sync por ocorrência (protocolo e situação do chamado), só quando grava.
 * - Nada muda no Help Desk e nada é enviado.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, prioridade, data, protocolo e
 * situação do chamado: nenhum assunto, descrição, morador ou solicitante. Até MAX_OCCURRENCES por vez.
 */
final class OccurrenceTicketSync
{
    public const MAX_OCCURRENCES = 500;
    public const FINAL = ['closed'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private TicketService $tickets;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?TicketService $tickets = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->tickets = $tickets ?? new TicketService($this->pdo, $this->scope);
    }

    /** O chamado encerra a ocorrência? (regra pura) Fechado sempre; resolvido só com include_resolved. */
    public static function settles(string $ticketStatus, bool $includeResolved): bool
    {
        return in_array($ticketStatus, self::FINAL, true) || ($includeResolved && $ticketStatus === 'resolved');
    }

    /** @param array<string, mixed> $input */
    public function sync(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $includeResolved = filter_var($input['include_resolved'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('occurrences')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'include_resolved' => $includeResolved, 'checked' => 0, 'settled' => 0,
            'still_open' => [], 'unavailable' => 0, 'occurrences' => []];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ticket_id, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . " FROM erp_occurrences WHERE condominium_id = ? AND deleted_at IS NULL AND status IN ('active', 'running') AND ticket_id IS NOT NULL
                ORDER BY id LIMIT " . (self::MAX_OCCURRENCES + 1) . ' FOR UPDATE');
            $stmt->execute([(int)$condominium]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > self::MAX_OCCURRENCES) {
                throw new InvalidArgumentException(sprintf('Mais de %d ocorrências com chamado em aberto: resolva em partes.', self::MAX_OCCURRENCES));
            }
            $result['checked'] = count($rows);
            foreach ($rows as $row) {
                $ticket = $this->tickets->findFor($user, (int)$row['ticket_id']);
                if ($ticket === null) {
                    $result['unavailable']++;
                    continue;
                }
                $status = (string)$ticket['status'];
                if (!self::settles($status, $includeResolved)) {
                    $result['still_open'][$status] = ($result['still_open'][$status] ?? 0) + 1;
                    continue;
                }
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $row[$field];
                }
                try {
                    $registry->save($user, 'occurrences', array_merge($data, ['status' => 'done']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_occurrence' => (string)$row['id']]);
                }
                $result['occurrences'][] = ['id' => (int)$row['id'], 'priority' => (string)$row['priority'], 'occurred_on' => substr((string)$row['occurred_on'], 0, 10),
                    'status_before' => (string)$row['status'], 'ticket_protocol' => (string)$ticket['protocol'], 'ticket_status' => $status];
            }
            $result['settled'] = count($result['occurrences']);
            ksort($result['still_open']);
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['occurrences'] as $o) {
                Audit::record('ticket_sync', 'erp_occurrences', $o['id'], ['status' => $o['status_before']],
                    ['status' => 'done', 'ticket_protocol' => $o['ticket_protocol'], 'ticket_status' => $o['ticket_status']]);
            }
        }
        $result['changed'] = $dryRun ? 0 : $result['settled'];
        return $result;
    }
}
