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
 * Encerramento em lote dos vínculos cujo fim já passou e que seguem ativos (ADR 0241). O cadastro de proprietários e
 * inquilinos (AP-005) guarda o fim do vínculo (ends_on), mas a situação só muda quando alguém abre o formulário: o
 * inquilino com o contrato terminado em março segue "ativo", conta na cobrança por e-mail, no portal do morador e nas
 * listas. O perfil de ocupação (ADR 0240) já desconta esses vínculos; esta ação acerta o cadastro.
 *
 * Por que não é a saída de moradores (ADR 0206): aquela trabalha numa unidade por vez, no dia da saída, e grava a data de
 * saída informada. Aqui o fim já está no cadastro: cada vínculo é encerrado com o próprio ends_on, em várias unidades de
 * uma vez, sem trocar a data.
 *
 * Reusa o que já existe: cada vínculo é regravado pelo RegistryService::save do cadastro "residents" só com a situação
 * inativa (valem as regras e a auditoria update do formulário), e, como na saída de moradores, os convites pendentes do
 * portal do morador dessas pessoas são revogados (nenhum convite vale para quem saiu). Nada é apagado e nada é enviado.
 *
 * - Entram: os vínculos ativos do condomínio com fim anterior a before (padrão hoje; nunca depois de hoje), dos tipos
 *   escolhidos (padrão todos), ou só os resident_ids informados. Regra pura problem(): vínculo não encontrado no
 *   condomínio, já encerrado, sem fim informado (use a saída de moradores), cujo fim ainda não passou, ou cuja unidade
 *   ficou inativa (o formulário não regrava: corrija no cadastro). Sem ids, os de unidade inativa ficam de fora e a
 *   contagem sai em skipped_inactive_unit.
 * - Tudo ou nada, numa transação com os vínculos e os convites travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _resident). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário, a dos convites revogados e um registro expire por vínculo (tipo, fim, dias desde o
 *   fim e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, tipo de vínculo, fim e dias
 * desde o fim: nenhum nome, documento, contato ou unidade. Até MAX_LINKS por vez; motivo obrigatório.
 */
final class ResidentLinkExpiry
{
    public const MAX_LINKS = 500;
    public const RELATIONSHIPS = ['owner', 'tenant', 'resident', 'financial'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o vínculo pode ser encerrado. */
    public static function problem(?array $row, string $before, bool $unitActive = true): ?string
    {
        $ends = substr((string)($row['ends_on'] ?? ''), 0, 10);
        return match (true) {
            $row === null => 'Vínculo não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O vínculo já está encerrado.',
            $ends === '' => 'O vínculo não tem fim informado: use a saída de moradores da unidade.',
            $ends >= $before => 'O fim do vínculo ainda não passou.',
            !$unitActive => 'A unidade do vínculo está inativa: corrija no cadastro.',
            default => null,
        };
    }

    /** Tipos de vínculo pedidos (regra pura): vazio = todos; tipo desconhecido = null. @return list<string>|null */
    public static function relationships(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return self::RELATIONSHIPS;
        }
        if (!is_array($raw)) {
            return null;
        }
        $picked = [];
        foreach ($raw as $value) {
            if (!is_string($value) || !in_array($value, self::RELATIONSHIPS, true)) {
                return null;
            }
            $picked[$value] = $value;
        }
        return array_values(array_intersect(self::RELATIONSHIPS, $picked));
    }

    /**
     * Ids dos vínculos cuja unidade (se houver) segue ativa: o formulário só regrava esses.
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
        $relationships = self::relationships($input['relationships'] ?? null);
        if ($relationships === null) {
            throw new InvalidArgumentException('Tipos de vínculo: owner, tenant, resident ou financial.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('resident_ids', $input) && $input['resident_ids'] !== null) {
            if (!is_array($input['resident_ids']) || $input['resident_ids'] === []) {
                throw new InvalidArgumentException('Vínculos: informe a lista de ids ou deixe vazio para todos os vencidos.');
            }
            $ids = [];
            foreach ($input['resident_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Vínculos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_LINKS) {
                throw new InvalidArgumentException(sprintf('Até %d vínculos por vez.', self::MAX_LINKS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('residents')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'relationships' => $relationships, 'reason' => $reason,
            'skipped_inactive_unit' => 0, 'invitations_revoked' => 0, 'links' => []];
        $invitations = [];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $marks = implode(',', array_fill(0, count($relationships), '?'));
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND ends_on IS NOT NULL AND ends_on < ? AND relationship IN ({$marks}) ORDER BY ends_on, id LIMIT " . (self::MAX_LINKS + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, $before], $relationships));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_LINKS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d vínculos vencidos: encerre em partes (data de corte menor).', self::MAX_LINKS));
                }
                $active = $this->activeUnits((int)$condominium, $rows);
                $kept = array_values(array_filter($rows, static fn (array $r): bool => isset($active[(int)$r['id']])));
                $result['skipped_inactive_unit'] = count($rows) - count($kept);
                $rows = $kept;
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
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
                        throw new RegistryValidationException(['_form' => $problem, '_resident' => (string)$id]);
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
                        in_array($field, ['starts_on', 'ends_on'], true) => substr((string)$value, 0, 10),
                        default => (string)$value,
                    };
                }
                try {
                    $registry->save($user, 'residents', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_resident' => (string)$row['id']]);
                }
                $ends = substr((string)$row['ends_on'], 0, 10);
                $result['links'][] = ['id' => (int)$row['id'], 'relationship' => (string)$row['relationship'], 'ends_on' => $ends,
                    'days_since_end' => (int)(new DateTimeImmutable($ends))->diff($today0)->days];
            }
            // Convites pendentes do portal do morador dessas pessoas: revogados, como na saída de moradores (ADR 0206).
            $linkIds = array_column($result['links'], 'id');
            if ($linkIds !== []) {
                $pending = $this->pdo->prepare("SELECT id FROM erp_resident_invitations WHERE condominium_id = ? AND status = 'pending' AND resident_id IN ("
                    . implode(',', array_fill(0, count($linkIds), '?')) . ') FOR UPDATE');
                $pending->execute(array_merge([(int)$condominium], $linkIds));
                $invitations = array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN));
                $revoke = $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'revoked', revoked_at = NOW() WHERE id = ? AND status = 'pending'");
                foreach ($invitations as $invitation) {
                    $revoke->execute([$invitation]);
                }
                $result['invitations_revoked'] = count($invitations);
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($invitations as $invitation) {
                Audit::record('update', 'erp_resident_invitations', $invitation, ['status' => 'pending'], ['status' => 'revoked', 'link_expired' => true]);
            }
            foreach ($result['links'] as $l) {
                Audit::record('expire', 'erp_residents', $l['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'relationship' => $l['relationship'], 'ends_on' => $l['ends_on'], 'days_since_end' => $l['days_since_end'],
                        'reason' => $reason, 'batch' => count($result['links'])]);
            }
        }
        $result['count'] = count($result['links']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
