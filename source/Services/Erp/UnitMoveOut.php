<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Saída de moradores da unidade (ADR 0206): quando o inquilino entrega o apartamento (ou a unidade é vendida), encerra de
 * uma vez os vínculos dele com a unidade, em vez de abrir cadastro por cadastro:
 *
 * - Vínculos (cadastro "residents") ativos da unidade com os tipos escolhidos (padrão inquilino e morador; proprietário e
 *   responsável financeiro só se pedidos): fim do vínculo = ends_on e situação inativa, regravados pelo
 *   RegistryService::save só com esses dois campos (vale a regra do formulário: fim depois do início).
 * - Convites pendentes do portal do morador (ADR 0090) dessas pessoas: revogados (nenhum convite vale para quem saiu).
 * - Veículos e animais da unidade: inativados só se pedidos (include_vehicles, include_pets), também pelo formulário.
 * - Nada é apagado e nada é enviado: sem e-mail, sem aviso (o histórico fica nos cadastros e na auditoria).
 *
 * Avisos (não impedem): unit_without_residents (a unidade fica sem nenhum vínculo ativo) e open_debt (a unidade tem
 * receitas vencidas em aberto, com quantidade e valor; a dívida é da unidade e continua).
 *
 * Tudo ou nada, numa transação com os registros travados (FOR UPDATE): um recusado pelo formulário desfaz todos
 * (RegistryValidationException com os erros, _registry e _record). dry_run passa por tudo e desfaz no fim. Auditoria: a
 * update do formulário em cada registro, a dos convites revogados e um registro move_out na unidade (data, motivo,
 * quantidades), só quando grava.
 *
 * Escopo (ADR-002): unidade do condomínio informado, no escopo do usuário (fora = CondominiumAccessDenied, 404; unidade
 * de outro condomínio ou excluída = RegistryNotFound, 404). Data de saída até hoje. Saem só ids, tipos de vínculo e
 * datas: nenhum nome, documento, e-mail, telefone ou placa.
 */
final class UnitMoveOut
{
    public const RELATIONSHIPS = ['owner', 'tenant', 'resident', 'financial'];
    public const DEFAULT_RELATIONSHIPS = ['tenant', 'resident'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Tipos de vínculo pedidos (regra pura): vazio = inquilino e morador; repetido conta uma vez; tipo desconhecido = null.
     *
     * @return list<string>|null
     */
    public static function relationships(mixed $raw): ?array
    {
        if ($raw === null || $raw === []) {
            return self::DEFAULT_RELATIONSHIPS;
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value) || !in_array($value, self::RELATIONSHIPS, true)) {
                return null;
            }
            $out[$value] = $value;
        }
        return array_values(array_intersect(self::RELATIONSHIPS, $out));
    }

    /** Data de saída válida (regra pura): AAAA-MM-DD existente e até hoje. */
    public static function validEndsOn(mixed $value, string $today): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value && $value <= $today;
    }

    /** @param array<string, mixed> $input */
    public function moveOut(User $user, array $input, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $unitId = filter_var($input['unit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null || $unitId === false || $unitId === null) {
            throw new InvalidArgumentException('Escolha o condomínio e a unidade.');
        }
        $relationships = self::relationships($input['relationships'] ?? null);
        if ($relationships === null) {
            throw new InvalidArgumentException('Vínculos: owner, tenant, resident ou financial.');
        }
        $endsOn = $input['ends_on'] ?? null;
        if (!self::validEndsOn($endsOn, $today)) {
            throw new InvalidArgumentException('Informe a data da saída (AAAA-MM-DD, até hoje).');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da saída (5 a 255 caracteres).');
        }
        $vehicles = filter_var($input['include_vehicles'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $pets = filter_var($input['include_pets'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        $unit = $this->pdo->prepare('SELECT id FROM erp_units WHERE id = ? AND condominium_id = ? AND deleted_at IS NULL');
        $unit->execute([(int)$unitId, (int)$condominium]);
        if ($unit->fetchColumn() === false) {
            throw new RegistryNotFound();
        }

        $registry = new RegistryService($this->pdo, $this->scope);
        $result = ['condominium_id' => (int)$condominium, 'unit_id' => (int)$unitId, 'dry_run' => $dryRun, 'ends_on' => $endsOn, 'reason' => $reason,
            'relationships' => $relationships, 'residents' => [], 'vehicles' => 0, 'pets' => 0, 'invitations_revoked' => 0, 'warnings' => []];
        $this->pdo->beginTransaction();
        try {
            $marks = implode(',', array_fill(0, count($relationships), '?'));
            $residents = $this->rows('residents', "relationship IN ({$marks})", array_merge([(int)$condominium, (int)$unitId], $relationships));
            if ($residents === []) {
                throw new InvalidArgumentException('A unidade não tem vínculo ativo dos tipos escolhidos.');
            }
            foreach ($residents as $row) {
                $this->resave($registry, $user, 'residents', $row, ['status' => 'inactive', 'ends_on' => $endsOn]);
                $result['residents'][] = ['id' => (int)$row['id'], 'relationship' => (string)$row['relationship'],
                    'starts_on' => $row['starts_on'] === null ? null : (string)$row['starts_on'], 'ends_on' => $endsOn];
            }
            $ids = array_map(static fn (array $r): int => (int)$r['id'], $residents);
            $pending = $this->pdo->prepare("SELECT id FROM erp_resident_invitations WHERE condominium_id = ? AND status = 'pending'
                AND resident_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $pending->execute(array_merge([(int)$condominium], $ids));
            $invitations = array_map('intval', $pending->fetchAll(PDO::FETCH_COLUMN));
            $revoke = $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'revoked', revoked_at = NOW() WHERE id = ? AND status = 'pending'");
            foreach ($invitations as $invitation) {
                $revoke->execute([$invitation]);
            }
            $result['invitations_revoked'] = count($invitations);
            foreach (['vehicles' => $vehicles, 'pets' => $pets] as $slug => $include) {
                if (!$include) {
                    continue;
                }
                foreach ($this->rows($slug, '1 = 1', [(int)$condominium, (int)$unitId]) as $row) {
                    $this->resave($registry, $user, $slug, $row, ['status' => 'inactive']);
                    $result[$slug]++;
                }
            }

            $left = $this->pdo->prepare("SELECT COUNT(*) FROM erp_residents WHERE condominium_id = ? AND unit_id = ? AND status = 'active' AND deleted_at IS NULL");
            $left->execute([(int)$condominium, (int)$unitId]);
            if ((int)$left->fetchColumn() === 0) {
                $result['warnings'][] = ['code' => 'unit_without_residents', 'message' => 'A unidade fica sem nenhum proprietário, inquilino ou morador ativo.'];
            }
            $debt = $this->pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents), 0) AS cents FROM erp_entries WHERE condominium_id = ? AND unit_id = ?
                AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND due_on < ?");
            $debt->execute([(int)$condominium, (int)$unitId, $today]);
            $open = $debt->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'cents' => 0];
            if ((int)$open['n'] > 0) {
                $result['warnings'][] = ['code' => 'open_debt', 'count' => (int)$open['n'], 'amount_cents' => (int)$open['cents'], 'message' => sprintf(
                    'A unidade tem %d conta(s) vencida(s) em aberto (R$ %s): a dívida é da unidade e continua.', (int)$open['n'],
                    number_format(((int)$open['cents']) / 100, 2, ',', '.'))];
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
                Audit::record('update', 'erp_resident_invitations', $invitation, ['status' => 'pending'], ['status' => 'revoked', 'move_out' => true]);
            }
            Audit::record('move_out', 'erp_units', (int)$unitId, null, ['ends_on' => $endsOn, 'reason' => $reason, 'relationships' => $relationships,
                'residents' => count($result['residents']), 'vehicles' => $result['vehicles'], 'pets' => $result['pets'], 'invitations_revoked' => count($invitations)]);
        }
        $result['changed'] = $dryRun ? 0 : count($result['residents']) + $result['vehicles'] + $result['pets'];
        return $result;
    }

    /** @return list<array<string, mixed>> registros ativos da unidade, travados, com as colunas do formulário. */
    private function rows(string $slug, string $filter, array $params): array
    {
        $definition = RegistryService::definition($slug);
        $fields = array_keys($definition['fields']);
        $stmt = $this->pdo->prepare('SELECT id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields))
            . " FROM `{$definition['table']}` WHERE condominium_id = ? AND unit_id = ? AND status = 'active' AND deleted_at IS NULL AND {$filter} ORDER BY id FOR UPDATE");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resave(RegistryService $registry, User $user, string $slug, array $row, array $changes): void
    {
        $data = [];
        foreach (array_keys(RegistryService::definition($slug)['fields']) as $field) {
            $data[$field] = $row[$field];
        }
        try {
            $registry->save($user, $slug, array_merge($data, $changes), (int)$row['id']);
        } catch (RegistryValidationException $error) {
            throw new RegistryValidationException($error->errors + ['_registry' => $slug, '_record' => (string)$row['id']]);
        }
    }
}
