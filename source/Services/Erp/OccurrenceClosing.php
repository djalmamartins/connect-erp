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
 * Encerramento em lote das ocorrências antigas sem chamado (ADR 0255). O cadastro de ocorrências (AP-112) registra
 * barulho, vazamento e dano; o relatório de ocorrências e o resumo do síndico contam as abertas e em andamento. As que
 * viraram chamado se acertam pela sincronização com o Help Desk (ADR 0213); as que nunca viraram chamado ficam "Aberta"
 * ou "Em andamento" para sempre quando ninguém volta ao formulário (o vazamento consertado, o barulho que passou), e
 * inflam a lista, o painel e o resumo. Hoje cada uma é resolvida à mão.
 *
 * Reusa o que já existe: cada ocorrência é regravada pelo RegistryService::save do cadastro "occurrences" só com a
 * situação "Resolvida" (valem as regras e a auditoria update do formulário), no mesmo desenho da sincronização com o
 * Help Desk (ADR 0213) e do cancelamento em lote dos empenhos (ADR 0239). Nada é apagado e nada é enviado.
 *
 * - Entram: as ocorrências abertas ou em andamento do condomínio, sem chamado, com a data há mais de older_than_days
 *   dias (padrão 90, de 30 a 3650), das prioridades escolhidas (padrão todas), ou só os occurrence_ids informados.
 *   Regra pura problem(): ocorrência não encontrada no condomínio, já resolvida, com chamado no Help Desk (acerta pela
 *   sincronização, ADR 0213), ainda dentro do prazo, ou de unidade inativa ou com o documento removido (o formulário não
 *   regrava: corrija no cadastro). Sem ids, as de chamado ficam de fora e a contagem sai em skipped_with_ticket; as de
 *   unidade inativa ou documento removido, em skipped_inactive_link.
 * - Tudo ou nada, numa transação com as ocorrências travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _occurrence). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro close_stale por ocorrência (situação anterior, prioridade, data,
 *   dias em aberto e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, prioridade, situação
 * anterior, data e dias em aberto: nenhum assunto, descrição, unidade ou documento. Até MAX_OCCURRENCES por vez; motivo
 * obrigatório.
 */
final class OccurrenceClosing
{
    public const MAX_OCCURRENCES = 500;
    public const MIN_DAYS = 30;
    public const DEFAULT_DAYS = 90;
    public const OPEN = ['active', 'running'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a ocorrência pode ser encerrada. $cutoff = último dia aceito. */
    public static function problem(?array $row, string $cutoff, bool $linksActive = true): ?string
    {
        return match (true) {
            $row === null => 'Ocorrência não encontrada neste condomínio.',
            !in_array($row['status'] ?? '', self::OPEN, true) => 'A ocorrência já está resolvida.',
            ($row['ticket_id'] ?? null) !== null => 'A ocorrência tem chamado no Help Desk: acerte pela sincronização com o Help Desk.',
            substr((string)($row['occurred_on'] ?? ''), 0, 10) > $cutoff => 'A ocorrência ainda está dentro do prazo.',
            !$linksActive => 'A unidade da ocorrência está inativa ou o documento foi removido: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Ids das ocorrências cuja unidade (se houver) segue ativa no condomínio e cujo documento (se houver) segue no Disco
     * Virtual do condomínio: o formulário só regrava essas.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function activeLinks(int $condominium, array $rows): array
    {
        $ids = static fn (string $column): array => array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r[$column] ?? 0), $rows))));
        $found = function (string $sql, array $values) use ($condominium): array {
            if ($values === []) {
                return [];
            }
            $stmt = $this->pdo->prepare($sql . ' AND id IN (' . implode(',', array_fill(0, count($values), '?')) . ')');
            $stmt->execute(array_merge([$condominium], $values));
            return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        };
        $units = $found("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'", $ids('unit_id'));
        $documents = $found('SELECT id FROM erp_documents WHERE condominium_id = ? AND deleted_at IS NULL', $ids('document_id'));
        $ok = [];
        foreach ($rows as $r) {
            $unit = (int)($r['unit_id'] ?? 0);
            $document = (int)($r['document_id'] ?? 0);
            if (($unit === 0 || isset($units[$unit])) && ($document === 0 || isset($documents[$document]))) {
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
        $days = $input['older_than_days'] ?? null;
        $days = $days === null || $days === '' ? self::DEFAULT_DAYS : $int($days);
        if ($days === false || $days < self::MIN_DAYS || $days > 3650) {
            throw new InvalidArgumentException(sprintf('Prazo: de %d a 3650 dias desde a ocorrência.', self::MIN_DAYS));
        }
        $options = array_keys(RegistryService::definition('occurrences')['fields']['priority']['options']);
        $priorities = $options;
        if (array_key_exists('priorities', $input) && $input['priorities'] !== null) {
            if (!is_array($input['priorities']) || $input['priorities'] === []
                || array_diff(array_map(static fn (mixed $p): string => is_string($p) ? $p : '', $input['priorities']), $options) !== []) {
                throw new InvalidArgumentException('Prioridades: ' . implode(', ', $options) . '.');
            }
            $priorities = array_values(array_unique($input['priorities']));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('occurrence_ids', $input) && $input['occurrence_ids'] !== null) {
            if (!is_array($input['occurrence_ids']) || $input['occurrence_ids'] === []) {
                throw new InvalidArgumentException('Ocorrências: informe a lista de ids ou deixe vazio para todas as antigas.');
            }
            $ids = [];
            foreach ($input['occurrence_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Ocorrências: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_OCCURRENCES) {
                throw new InvalidArgumentException(sprintf('Até %d ocorrências por vez.', self::MAX_OCCURRENCES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $today = new DateTimeImmutable('today');
        $cutoff = $today->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $fields = array_keys(RegistryService::definition('occurrences')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ticket_id, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'older_than_days' => (int)$days, 'occurred_until' => $cutoff,
            'priorities' => $priorities, 'reason' => $reason, 'skipped_with_ticket' => 0, 'skipped_inactive_link' => 0, 'occurrences' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_occurrences WHERE condominium_id = ? AND deleted_at IS NULL AND status IN ('active', 'running')
                    AND occurred_on <= ? AND priority IN (" . implode(',', array_fill(0, count($priorities), '?')) . ')
                    ORDER BY occurred_on, id LIMIT ' . (self::MAX_OCCURRENCES + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, $cutoff], $priorities));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_OCCURRENCES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d ocorrências antigas: encerre em partes (prazo maior ou por prioridade).', self::MAX_OCCURRENCES));
                }
                $withTicket = array_filter($rows, static fn (array $r): bool => $r['ticket_id'] !== null);
                $result['skipped_with_ticket'] = count($withTicket);
                $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['ticket_id'] === null));
                $active = $this->activeLinks((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_link'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_occurrences WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
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
                        throw new RegistryValidationException(['_form' => $problem, '_occurrence' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = $value === null ? null : ($field === 'occurred_on' ? substr((string)$value, 0, 10) : (string)$value);
                }
                try {
                    $registry->save($user, 'occurrences', array_merge($data, ['status' => 'done']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_occurrence' => (string)$row['id']]);
                }
                $on = substr((string)$row['occurred_on'], 0, 10);
                $result['occurrences'][] = ['id' => (int)$row['id'], 'priority' => (string)$row['priority'], 'from_status' => (string)$row['status'],
                    'occurred_on' => $on, 'days_open' => (int)(new DateTimeImmutable($on))->diff($today)->days];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['occurrences'] as $o) {
                Audit::record('close_stale', 'erp_occurrences', $o['id'], ['status' => $o['from_status']],
                    ['status' => 'done', 'priority' => $o['priority'], 'occurred_on' => $o['occurred_on'], 'days_open' => $o['days_open'], 'reason' => $reason,
                        'batch' => count($result['occurrences'])]);
            }
        }
        $result['count'] = count($result['occurrences']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
