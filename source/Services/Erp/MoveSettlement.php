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
 * Baixa em lote dos agendamentos de mudança com a data já passada (ADR 0247). O cadastro de agendamentos (AP-120) tem as
 * situações "Agendada", "Realizada" e "Cancelada", mas a portaria raramente volta ao formulário depois da mudança: o
 * agendamento de março segue "Agendada", aparece no relatório de mudanças (AP-095) como se ainda fosse acontecer e não
 * se sabe quais saídas aconteceram. Nenhuma ação em lote trata dos agendamentos.
 *
 * Reusa o que já existe: cada agendamento é regravado pelo RegistryService::save do cadastro "moves" só com a situação
 * escolhida (outcome: done = Realizada, padrão; inactive = Cancelada, para quem não apareceu). Valem as regras e a
 * auditoria update do formulário (unidade ativa, um agendamento por período e dia); a data, o período e os demais campos
 * ficam como estão. Nada é apagado e nada é enviado.
 *
 * - Entram: os agendamentos "Agendada" do condomínio com a data anterior a before (padrão hoje; nunca depois de hoje: a
 *   mudança de hoje ainda pode acontecer), dos tipos escolhidos (entrada, saída; padrão os dois), ou só os move_ids
 *   informados. Regra pura problem(): não encontrado no condomínio, já realizado ou cancelado, com a data ainda por vir ou
 *   de unidade inativa (o formulário não regrava: corrija no cadastro). Sem ids, os de unidade inativa ficam de fora e a
 *   contagem sai em skipped_inactive_unit.
 * - Tudo ou nada, numa transação com os agendamentos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _move). dry_run passa por tudo e desfaz.
 * - move_outs_to_review: com outcome done, as unidades de uma saída baixada que ainda têm inquilino ou morador ativo no
 *   cadastro; elas não são tocadas, só apontadas para a saída de moradores da unidade (ADR 0206).
 * - Auditoria: a update do formulário e um registro settle por agendamento (tipo, data, período, dias desde a data,
 *   resultado e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, tipo, data, período e dias
 * desde a data: nenhum morador, unidade ou observação. Até MAX_MOVES por vez; motivo obrigatório.
 */
final class MoveSettlement
{
    public const MAX_MOVES = 500;
    public const OUTCOMES = ['done', 'inactive'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<string> */
    public static function kindOptions(): array
    {
        return array_keys(RegistryService::definition('moves')['fields']['kind']['options']);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o agendamento pode ser baixado. */
    public static function problem(?array $row, string $before, bool $unitActive = true): ?string
    {
        $date = substr((string)($row['scheduled_on'] ?? ''), 0, 10);
        return match (true) {
            $row === null => 'Agendamento não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O agendamento já foi realizado ou cancelado.',
            $date === '' || $date >= $before => 'A data do agendamento ainda não passou.',
            !$unitActive => 'A unidade do agendamento está inativa: corrija no cadastro.',
            default => null,
        };
    }

    /** Tipos pedidos (regra pura): vazio = todos; tipo desconhecido = null. @return list<string>|null */
    public static function kinds(mixed $raw): ?array
    {
        $options = self::kindOptions();
        if ($raw === null || $raw === '' || $raw === []) {
            return $options;
        }
        if (!is_array($raw)) {
            return null;
        }
        $picked = [];
        foreach ($raw as $value) {
            if (!is_string($value) || !in_array($value, $options, true)) {
                return null;
            }
            $picked[$value] = $value;
        }
        return array_values(array_intersect($options, $picked));
    }

    /**
     * Ids dos agendamentos cuja unidade segue ativa: o formulário só regrava esses.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function activeUnits(int $condominium, array $rows): array
    {
        $units = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['unit_id'] ?? 0), $rows))));
        $ok = [];
        if ($units !== []) {
            $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND id IN ("
                . implode(',', array_fill(0, count($units), '?')) . ')');
            $stmt->execute(array_merge([$condominium], $units));
            $ok = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $result = [];
        foreach ($rows as $r) {
            if (isset($ok[(int)($r['unit_id'] ?? 0)])) {
                $result[(int)$r['id']] = true;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $input */
    public function settle(User $user, array $input): array
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
        $outcome = $input['outcome'] ?? 'done';
        if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado: done (Realizada) ou inactive (Cancelada).');
        }
        $kinds = self::kinds($input['kinds'] ?? null);
        if ($kinds === null) {
            throw new InvalidArgumentException('Tipos: ' . implode(', ', self::kindOptions()) . '.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('move_ids', $input) && $input['move_ids'] !== null) {
            if (!is_array($input['move_ids']) || $input['move_ids'] === []) {
                throw new InvalidArgumentException('Agendamentos: informe a lista de ids ou deixe vazio para todos os passados.');
            }
            $ids = [];
            foreach ($input['move_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Agendamentos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_MOVES) {
                throw new InvalidArgumentException(sprintf('Até %d agendamentos por vez.', self::MAX_MOVES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('moves')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'outcome' => $outcome, 'kinds' => $kinds,
            'reason' => $reason, 'skipped_inactive_unit' => 0, 'move_outs_to_review' => 0, 'moves' => []];
        $moveOutUnits = [];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $marks = implode(',', array_fill(0, count($kinds), '?'));
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_moves WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND scheduled_on < ? AND kind IN ({$marks}) ORDER BY scheduled_on, id LIMIT " . (self::MAX_MOVES + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, $before], $kinds));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_MOVES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d agendamentos passados: baixe em partes (data de corte menor).', self::MAX_MOVES));
                }
                $active = $this->activeUnits((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_unit'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_moves WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $active = $this->activeUnits((int)$condominium, array_values($byId));
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $before, isset($active[$id]));
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_move' => (string)$id]);
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
                        $field === 'scheduled_on' => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'moves', array_merge($data, ['status' => $outcome]), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_move' => (string)$row['id']]);
                }
                $day = substr((string)$row['scheduled_on'], 0, 10);
                if ($outcome === 'done' && $row['kind'] === 'saida') {
                    $moveOutUnits[(int)$row['unit_id']] = (int)$row['unit_id'];
                }
                $result['moves'][] = ['id' => (int)$row['id'], 'kind' => (string)$row['kind'], 'scheduled_on' => $day, 'period' => (string)$row['period'],
                    'days_since' => (int)(new DateTimeImmutable($day))->diff($today0)->days];
            }
            if ($moveOutUnits !== []) {
                // Saídas realizadas cujas unidades ainda têm inquilino ou morador ativo: apontadas para a saída de moradores (ADR 0206).
                $review = $this->pdo->prepare("SELECT COUNT(DISTINCT unit_id) FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND relationship IN ('tenant', 'resident') AND unit_id IN (" . implode(',', array_fill(0, count($moveOutUnits), '?')) . ')');
                $review->execute(array_merge([(int)$condominium], array_values($moveOutUnits)));
                $result['move_outs_to_review'] = (int)$review->fetchColumn();
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['moves'] as $m) {
                Audit::record('settle', 'erp_moves', $m['id'], ['status' => 'active'],
                    ['status' => $outcome, 'kind' => $m['kind'], 'scheduled_on' => $m['scheduled_on'], 'period' => $m['period'], 'days_since' => $m['days_since'],
                        'reason' => $reason, 'batch' => count($result['moves'])]);
            }
        }
        $result['count'] = count($result['moves']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
