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
 * Encerramento em lote dos mandatos vencidos do síndico e do conselho (ADR 0243). O cadastro "Síndico e conselho" (AP-014)
 * guarda o fim do mandato (term_ends_on), mas a situação só muda quando alguém abre o formulário: o conselho eleito há
 * dois anos segue "ativo" depois da assembleia que elegeu o novo, e a regra de um síndico ativo por condomínio
 * (single_active) recusa o cadastro do síndico novo enquanto o antigo não for inativado. O calendário de obrigações e o
 * painel operacional já mostram os mandatos vencendo; esta ação acerta o cadastro.
 *
 * Reusa o que já existe: cada membro é regravado pelo RegistryService::save do cadastro "board" só com a situação inativa
 * (valem as regras e a auditoria update do formulário); o fim do mandato e os demais campos ficam como estão. Nada é
 * apagado e nada é enviado.
 *
 * - Entram: os membros ativos do condomínio com o fim do mandato anterior a before (padrão hoje; nunca depois de hoje),
 *   dos cargos escolhidos (padrão todos), ou só os member_ids informados. Regra pura problem(): membro não encontrado no
 *   condomínio, já inativo, sem fim de mandato, com o mandato ainda valendo (o próprio dia vale), de unidade inativa ou
 *   sem e-mail ou telefone (obrigatórios desde a ADR 0151: o formulário não regrava). Sem ids, esses dois últimos ficam
 *   de fora e as contagens saem em skipped_inactive_unit e skipped_incomplete.
 * - Tudo ou nada, numa transação com os membros travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _member). dry_run passa por tudo e desfaz.
 * - sindico_active_after: síndicos ativos que restam no condomínio depois do encerramento (0 = cadastre o novo).
 * - Auditoria: a update do formulário e um registro expire por membro (cargo, fim, dias desde o fim e motivo), só quando
 *   grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, cargo, fim do mandato e dias
 * desde o fim: nenhum nome, documento, contato ou unidade. Até MAX_MEMBERS por vez; motivo obrigatório.
 */
final class BoardTermExpiry
{
    public const MAX_MEMBERS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<string> */
    public static function roleOptions(): array
    {
        return array_keys(RegistryService::definition('board')['fields']['role']['options']);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o mandato pode ser encerrado. */
    public static function problem(?array $row, string $before, bool $unitActive = true): ?string
    {
        $ends = substr((string)($row['term_ends_on'] ?? ''), 0, 10);
        return match (true) {
            $row === null => 'Membro não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O membro já está inativo.',
            $ends === '' => 'O mandato não tem fim informado: corrija no cadastro.',
            $ends >= $before => 'O mandato ainda não terminou.',
            !$unitActive => 'A unidade do membro está inativa: corrija no cadastro.',
            !self::complete($row) => 'O cadastro está sem e-mail ou telefone (obrigatórios): complete no cadastro.',
            default => null,
        };
    }

    /** E-mail e telefone preenchidos, como o formulário exige (regra pura). */
    public static function complete(array $row): bool
    {
        return trim((string)($row['email'] ?? '')) !== '' && trim((string)($row['phone'] ?? '')) !== '';
    }

    /** Cargos pedidos (regra pura): vazio = todos; cargo desconhecido = null. @return list<string>|null */
    public static function roles(mixed $raw): ?array
    {
        $options = self::roleOptions();
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
     * Ids dos membros cuja unidade (se houver) segue ativa: o formulário só regrava esses.
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
            $unit = (int)($r['unit_id'] ?? 0);
            if ($unit === 0 || isset($ok[$unit])) {
                $result[(int)$r['id']] = true;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $input */
    public function expire(User $user, array $input): array
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
        $roles = self::roles($input['roles'] ?? null);
        if ($roles === null) {
            throw new InvalidArgumentException('Cargos: ' . implode(', ', self::roleOptions()) . '.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('member_ids', $input) && $input['member_ids'] !== null) {
            if (!is_array($input['member_ids']) || $input['member_ids'] === []) {
                throw new InvalidArgumentException('Membros: informe a lista de ids ou deixe vazio para todos os vencidos.');
            }
            $ids = [];
            foreach ($input['member_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Membros: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_MEMBERS) {
                throw new InvalidArgumentException(sprintf('Até %d membros por vez.', self::MAX_MEMBERS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('board')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'roles' => $roles, 'reason' => $reason,
            'skipped_inactive_unit' => 0, 'skipped_incomplete' => 0, 'members' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $marks = implode(',', array_fill(0, count($roles), '?'));
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_board_members WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND term_ends_on IS NOT NULL AND term_ends_on < ? AND role IN ({$marks}) ORDER BY term_ends_on, id LIMIT " . (self::MAX_MEMBERS + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, $before], $roles));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_MEMBERS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d mandatos vencidos: encerre em partes (data de corte menor).', self::MAX_MEMBERS));
                }
                $active = $this->activeUnits((int)$condominium, $rows);
                $kept = [];
                foreach ($rows as $r) {
                    if (!isset($active[(int)$r['id']])) {
                        $result['skipped_inactive_unit']++;
                    } elseif (!self::complete($r)) {
                        $result['skipped_incomplete']++;
                    } else {
                        $kept[] = $r;
                    }
                }
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_board_members WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
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
                        throw new RegistryValidationException(['_form' => $problem, '_member' => (string)$id]);
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
                        in_array($field, ['term_starts_on', 'term_ends_on'], true) => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'board', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_member' => (string)$row['id']]);
                }
                $ends = substr((string)$row['term_ends_on'], 0, 10);
                $result['members'][] = ['id' => (int)$row['id'], 'role' => (string)$row['role'], 'term_ends_on' => $ends,
                    'days_since_end' => (int)(new DateTimeImmutable($ends))->diff($today0)->days];
            }
            $left = $this->pdo->prepare("SELECT COUNT(*) FROM erp_board_members WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND role = 'sindico'");
            $left->execute([(int)$condominium]);
            $result['sindico_active_after'] = (int)$left->fetchColumn();
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['members'] as $m) {
                Audit::record('expire', 'erp_board_members', $m['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'role' => $m['role'], 'term_ends_on' => $m['term_ends_on'], 'days_since_end' => $m['days_since_end'],
                        'reason' => $reason, 'batch' => count($result['members'])]);
            }
        }
        $result['count'] = count($result['members']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
