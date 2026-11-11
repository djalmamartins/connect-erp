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
 * Conclusão de manutenções com a próxima agendada (ADR 0208): o zelador ou a administradora registra de uma vez as
 * manutenções feitas (limpeza da caixa d'água, revisão do elevador, recarga dos extintores) e, nas periódicas, a próxima
 * já fica agendada; hoje a conclusão é uma por uma e a próxima depende de alguém lembrar de cadastrar.
 *
 * - Cada manutenção (cadastro "maintenances", AP-110) agendada ou em execução do condomínio é regravada pelo
 *   RegistryService::save só com done_on (até hoje) e a situação "Concluída" (valem as regras do formulário).
 * - Periódica (mensal, trimestral, semestral, anual), com schedule_next (padrão sim): cria a próxima pelo formulário,
 *   com o mesmo serviço, fornecedor, periodicidade e descrição, sem anexo, agendada na data da regra pura nextPlannedOn:
 *   a data prevista mais o período (fim de mês ajustado: 31/01 + 1 mês = 28/02), avançando até passar da conclusão (uma
 *   manutenção atrasada não gera a próxima já vencida; o calendário do contrato se mantém). Se já existe a mesma
 *   manutenção agendada nessa data, não cria outra (already_scheduled).
 * - Recusa (regra pura problem): manutenção não encontrada no condomínio; já concluída; cancelada.
 *
 * Tudo ou nada, numa transação com as manutenções travadas (FOR UPDATE): uma recusada (aqui ou pelo formulário, por
 * exemplo fornecedor inativo na próxima) desfaz todas (RegistryValidationException com os erros e _maintenance). dry_run
 * passa por tudo e desfaz no fim. Auditoria: a do formulário (update da concluída, create da próxima) e um registro
 * complete por manutenção (data, próxima), só quando grava. Nada é enviado.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404); até MAX_ITEMS por vez.
 */
final class MaintenanceCompletion
{
    public const MAX_ITEMS = 100;
    public const MONTHS = ['mensal' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Soma meses mantendo o dia, ajustado ao fim do mês (regra pura): 2026-01-31 + 1 = 2026-02-28. */
    public static function addMonths(string $date, int $months): string
    {
        $d = new \DateTimeImmutable($date);
        $first = $d->modify('first day of this month')->modify(sprintf('+%d months', $months));
        $day = min((int)$d->format('d'), (int)$first->format('t'));
        return $first->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    }

    /** Próxima data prevista (regra pura): null para "única"; senão prevista + N períodos, a primeira depois da conclusão. */
    public static function nextPlannedOn(string $plannedOn, string $doneOn, string $frequency): ?string
    {
        $months = self::MONTHS[$frequency] ?? null;
        if ($months === null) {
            return null;
        }
        for ($n = 1; $n <= 1200; $n++) {
            $next = self::addMonths($plannedOn, $months * $n);   // sempre a partir da prevista: 31/01 → 28/02 → 31/03
            if ($next > $doneOn) {
                return $next;
            }
        }
        return null;
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando segue. */
    public static function problem(?array $row): ?string
    {
        return match (true) {
            $row === null => 'Manutenção não encontrada neste condomínio.',
            ($row['status'] ?? '') === 'done' => 'Manutenção já concluída.',
            ($row['status'] ?? '') === 'inactive' => 'Manutenção cancelada.',
            default => null,
        };
    }

    /**
     * Itens {id, done_on} validados (regra pura); id repetido = inválido.
     *
     * @return array<int, string> id => done_on
     */
    public static function items(mixed $raw, string $today): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('Escolha as manutenções concluídas.');
        }
        if (count($raw) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf('Conclua até %d manutenções por vez.', self::MAX_ITEMS));
        }
        $out = [];
        foreach ($raw as $item) {
            $id = is_array($item) && (is_int($item['id'] ?? null) || is_string($item['id'] ?? null))
                ? filter_var($item['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $done = is_array($item) ? ($item['done_on'] ?? null) : null;
            $date = is_string($done) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $done) : false;
            if ($id === false || $date === false || $date->format('Y-m-d') !== $done || $done > $today || isset($out[$id])) {
                throw new InvalidArgumentException('Cada item: {id, done_on} com data AAAA-MM-DD até hoje, sem repetir a manutenção.');
            }
            $out[$id] = $done;
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    public function complete(User $user, array $input, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $items = self::items($input['items'] ?? null, $today);
        $scheduleNext = filter_var($input['schedule_next'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('maintenances')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $ids = array_keys($items);
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'schedule_next' => $scheduleNext, 'count' => count($ids),
            'completed' => 0, 'scheduled' => 0, 'already_scheduled' => 0, 'maintenances' => []];
        $before = [];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
                . ' FROM erp_maintenances WHERE condominium_id = ? AND deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute(array_merge([(int)$condominium], $ids));
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[(int)$r['id']] = $r;
            }
            $exists = $this->pdo->prepare("SELECT id FROM erp_maintenances WHERE condominium_id = ? AND service = ? AND planned_on = ?
                AND status IN ('active', 'running') AND deleted_at IS NULL LIMIT 1");
            foreach ($items as $id => $doneOn) {
                $row = $rows[$id] ?? null;
                $problem = self::problem($row);
                if ($problem !== null) {
                    throw new RegistryValidationException(['_form' => $problem, '_maintenance' => (string)$id]);
                }
                $data = [];
                foreach ($fields as $field) {
                    $data[$field] = $row[$field];
                }
                try {
                    $registry->save($user, 'maintenances', array_merge($data, ['done_on' => $doneOn, 'status' => 'done']), $id);
                    $next = $scheduleNext ? self::nextPlannedOn(substr((string)$row['planned_on'], 0, 10), $doneOn, (string)$row['frequency']) : null;
                    $nextId = null;
                    $already = false;
                    if ($next !== null) {
                        $exists->execute([(int)$condominium, (string)$row['service'], $next]);
                        $found = $exists->fetchColumn();
                        if ($found !== false) {
                            $already = true;
                            $nextId = (int)$found;
                        } else {
                            $nextId = (int)$registry->save($user, 'maintenances', array_merge($data, ['planned_on' => $next, 'done_on' => null,
                                'document_id' => null, 'status' => 'active']))['id'];
                        }
                    }
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_maintenance' => (string)$id]);
                }
                $result['completed']++;
                if ($already) {
                    $result['already_scheduled']++;
                } elseif ($next !== null) {
                    $result['scheduled']++;
                }
                $before[$id] = (string)$row['status'];
                $result['maintenances'][] = ['id' => $id, 'service' => (string)$row['service'], 'frequency' => (string)$row['frequency'],
                    'planned_on' => substr((string)$row['planned_on'], 0, 10), 'done_on' => $doneOn,
                    'next' => $next === null ? null : ['id' => $dryRun && !$already ? null : $nextId, 'planned_on' => $next, 'already_scheduled' => $already]];
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
                Audit::record('complete', 'erp_maintenances', $m['id'], ['status' => $before[$m['id']]], ['status' => 'done', 'done_on' => $m['done_on'],
                    'next_id' => $m['next']['id'] ?? null, 'next_planned_on' => $m['next']['planned_on'] ?? null, 'batch' => count($ids)]);
            }
        }
        $result['changed'] = $dryRun ? 0 : $result['completed'] + $result['scheduled'];
        return $result;
    }
}
