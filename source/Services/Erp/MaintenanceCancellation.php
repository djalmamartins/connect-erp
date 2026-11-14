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
 * Cancelamento em lote das manutenções agendadas e esquecidas (ADR 0261). O cadastro de manutenções (AP-110) agenda as
 * preventivas e corretivas; a conclusão em lote (ADR 0208) registra as feitas e agenda a próxima; a conformidade das
 * manutenções (ADR 0248) conta as atrasadas. Sobram as agendadas que não vão acontecer: o serviço que mudou de
 * fornecedor e foi cadastrado de novo, a manutenção duplicada, a obra desistida. Elas ficam "Agendada" para sempre,
 * aparecem como atrasadas e derrubam a taxa de pontualidade. Hoje cada uma é cancelada à mão.
 *
 * Reusa o que já existe: cada manutenção é regravada pelo RegistryService::save do cadastro "maintenances" só com a
 * situação "Cancelada" (valem as regras e a auditoria update do formulário), no mesmo desenho do cancelamento em lote dos
 * empenhos (ADR 0239) e do encerramento das ocorrências antigas (ADR 0255). Nada é apagado, nenhuma próxima é agendada e
 * nada é enviado.
 *
 * - Entram: as manutenções agendadas do condomínio com a data prevista há mais de older_than_days dias (padrão 60, de 15
 *   a 3650), das periodicidades escolhidas (padrão todas), ou só os maintenance_ids informados. Regra pura problem():
 *   manutenção não encontrada no condomínio, em execução (conclua pelo formulário ou pela conclusão em lote), já
 *   concluída ou cancelada, ainda dentro do prazo, ou de fornecedor inativo ou documento removido (o formulário não
 *   regrava: corrija no cadastro). Sem ids, essas últimas ficam de fora e a contagem sai em skipped_inactive_link.
 * - periodic: quantas das canceladas são periódicas; a próxima não é agendada, e o calendário dessas deve ser revisto no
 *   cadastro.
 * - Tudo ou nada, numa transação com as manutenções travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _maintenance). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro cancel_overdue por manutenção (periodicidade, data prevista, dias de
 *   atraso e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, periodicidade, data prevista
 * e dias de atraso: nenhum serviço, fornecedor, descrição ou documento. Até MAX_MAINTENANCES por vez; motivo obrigatório.
 */
final class MaintenanceCancellation
{
    public const MAX_MAINTENANCES = 500;
    public const MIN_DAYS = 15;
    public const DEFAULT_DAYS = 60;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a manutenção pode ser cancelada. $cutoff = última data prevista aceita. */
    public static function problem(?array $row, string $cutoff, bool $linksActive = true): ?string
    {
        return match (true) {
            $row === null => 'Manutenção não encontrada neste condomínio.',
            ($row['status'] ?? '') === 'running' => 'A manutenção está em execução: conclua pelo formulário ou pela conclusão em lote.',
            ($row['status'] ?? '') !== 'active' => 'A manutenção já foi concluída ou cancelada.',
            substr((string)($row['planned_on'] ?? ''), 0, 10) > $cutoff => 'A manutenção ainda está dentro do prazo.',
            !$linksActive => 'O fornecedor da manutenção está inativo ou o documento foi removido: corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Ids das manutenções cujo fornecedor (se houver) segue ativo e cujo documento (se houver) segue no Disco Virtual do
     * condomínio: o formulário só regrava essas.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function activeLinks(int $condominium, array $rows): array
    {
        $ids = static fn (string $column): array => array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r[$column] ?? 0), $rows))));
        $found = function (string $sql, array $params, array $values): array {
            if ($values === []) {
                return [];
            }
            $stmt = $this->pdo->prepare($sql . ' AND id IN (' . implode(',', array_fill(0, count($values), '?')) . ')');
            $stmt->execute(array_merge($params, $values));
            return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        };
        // Fornecedor é cadastro geral (sem condomínio): basta seguir ativo.
        $suppliers = $found("SELECT id FROM erp_suppliers WHERE deleted_at IS NULL AND status = 'active'", [], $ids('supplier_id'));
        $documents = $found('SELECT id FROM erp_documents WHERE condominium_id = ? AND deleted_at IS NULL', [$condominium], $ids('document_id'));
        $ok = [];
        foreach ($rows as $r) {
            $supplier = (int)($r['supplier_id'] ?? 0);
            $document = (int)($r['document_id'] ?? 0);
            if (($supplier === 0 || isset($suppliers[$supplier])) && ($document === 0 || isset($documents[$document]))) {
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
            throw new InvalidArgumentException(sprintf('Prazo: de %d a 3650 dias de atraso.', self::MIN_DAYS));
        }
        $options = array_keys(RegistryService::definition('maintenances')['fields']['frequency']['options']);
        $frequencies = $options;
        if (array_key_exists('frequencies', $input) && $input['frequencies'] !== null) {
            if (!is_array($input['frequencies']) || $input['frequencies'] === []
                || array_diff(array_map(static fn (mixed $f): string => is_string($f) ? $f : '', $input['frequencies']), $options) !== []) {
                throw new InvalidArgumentException('Periodicidades: ' . implode(', ', $options) . '.');
            }
            $frequencies = array_values(array_intersect($options, $input['frequencies']));
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('maintenance_ids', $input) && $input['maintenance_ids'] !== null) {
            if (!is_array($input['maintenance_ids']) || $input['maintenance_ids'] === []) {
                throw new InvalidArgumentException('Manutenções: informe a lista de ids ou deixe vazio para todas as atrasadas.');
            }
            $ids = [];
            foreach ($input['maintenance_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Manutenções: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_MAINTENANCES) {
                throw new InvalidArgumentException(sprintf('Até %d manutenções por vez.', self::MAX_MAINTENANCES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $today = new DateTimeImmutable('today');
        $cutoff = $today->modify(sprintf('-%d days', $days))->format('Y-m-d');
        $fields = array_keys(RegistryService::definition('maintenances')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'older_than_days' => (int)$days, 'planned_until' => $cutoff,
            'frequencies' => $frequencies, 'reason' => $reason, 'skipped_inactive_link' => 0, 'maintenances' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND planned_on <= ? AND frequency IN (" . implode(',', array_fill(0, count($frequencies), '?')) . ')
                    ORDER BY planned_on, id LIMIT ' . (self::MAX_MAINTENANCES + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, $cutoff], $frequencies));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_MAINTENANCES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d manutenções atrasadas: cancele em partes (prazo maior ou por periodicidade).', self::MAX_MAINTENANCES));
                }
                $active = $this->activeLinks((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_link'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
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
                        throw new RegistryValidationException(['_form' => $problem, '_maintenance' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = $value === null ? null : (in_array($field, ['planned_on', 'done_on'], true) ? substr((string)$value, 0, 10) : (string)$value);
                }
                try {
                    $registry->save($user, 'maintenances', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_maintenance' => (string)$row['id']]);
                }
                $on = substr((string)$row['planned_on'], 0, 10);
                $result['maintenances'][] = ['id' => (int)$row['id'], 'frequency' => (string)$row['frequency'], 'planned_on' => $on,
                    'days_overdue' => (int)(new DateTimeImmutable($on))->diff($today)->days];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['maintenances'] as $m) {
                Audit::record('cancel_overdue', 'erp_maintenances', $m['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'frequency' => $m['frequency'], 'planned_on' => $m['planned_on'], 'days_overdue' => $m['days_overdue'], 'reason' => $reason,
                        'batch' => count($result['maintenances'])]);
            }
        }
        $result['count'] = count($result['maintenances']);
        $result['periodic'] = count(array_filter($result['maintenances'], static fn (array $m): bool => $m['frequency'] !== 'unica'));
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
