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
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Support\Audit;

/**
 * Inativação em lote dos veículos de unidades sem morador ativo (ADR 0267). O cadastro de veículos (AP-011) guarda os
 * veículos autorizados por unidade, para a portaria e o controle de vagas. A saída da unidade (ADR 0206) só inativa os
 * veículos quando pedido, unidade por unidade; o encerramento dos vínculos vencidos (ADR 0241) acerta os moradores e não
 * toca os veículos. Ficam então veículos "Ativo" de quem já não mora ali: a portaria abre a garagem para a placa e a vaga
 * segue ocupada no cadastro.
 *
 * Reusa o que já existe: cada veículo é regravado pelo RegistryService::save do cadastro "vehicles" só com a situação
 * "Inativo" (valem as regras e a auditoria update do formulário), no mesmo desenho do bloqueio em lote dos visitantes
 * (ADR 0265), com a mesma regra de morador ativo (vínculo ativo, sem excluído e sem fim de vínculo passado). Antes de
 * gravar, a regra pura do formulário (RegistryValidator::validate, como nas importações) aponta o cadastro antigo que
 * o formulário não regrava (placa fora do padrão, por exemplo). Nada vai ao morador; para reativar, o formulário.
 *
 * - Entram: os veículos ativos do condomínio vinculados a uma unidade (unit_id) sem nenhum morador ativo, ou só os
 *   vehicle_ids informados. Regra pura problem(): veículo não encontrado no condomínio, já inativo, sem unidade vinculada
 *   (só o texto da unidade: vincule no cadastro), cuja unidade ainda tem morador ativo, cuja unidade está inativa, ou com
 *   erro no formulário (corrija no cadastro). Sem ids, os sem unidade vinculada ficam de fora e a contagem sai em
 *   skipped_unlinked; os de unidade inativa, em skipped_inactive_unit; os com erro no formulário, em skipped_invalid.
 * - Tudo ou nada, numa transação com os veículos travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form ou os erros do formulário, e _vehicle). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro deactivate_unattached por veículo (tipo e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id e tipo do veículo, e as
 * contagens por tipo: nenhuma placa, modelo, cor, vaga, responsável, unidade ou observação. Até MAX_VEHICLES por vez;
 * motivo obrigatório.
 */
final class VehicleUnitRelease
{
    public const MAX_VEHICLES = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando o veículo pode ser inativado. */
    public static function problem(?array $row, bool $unitHasResident, bool $unitActive = true, ?string $formError = null): ?string
    {
        return match (true) {
            $row === null => 'Veículo não encontrado neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'O veículo já está inativo.',
            ($row['unit_id'] ?? null) === null => 'O veículo não tem unidade vinculada (só o texto da unidade): vincule a unidade no cadastro.',
            $unitHasResident => 'A unidade do veículo ainda tem morador ativo.',
            !$unitActive => 'A unidade do veículo está inativa: o formulário não regrava. Corrija no cadastro.',
            $formError !== null => 'O formulário não regrava o veículo (' . $formError . '). Corrija no cadastro.',
            default => null,
        };
    }

    /**
     * Primeiro erro do formulário para os dados do veículo como Inativo (regra pura, sem banco), só com o rótulo do
     * campo e a mensagem do formulário: nenhum valor do cadastro.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     */
    public static function formError(array $definition, array $data): ?string
    {
        [, $errors] = RegistryValidator::validate($definition, array_merge($data, ['status' => 'inactive']));
        foreach ($errors as $field => $message) {
            return ($definition['fields'][$field]['label'] ?? $field) . ': ' . $message;
        }
        return null;
    }

    /**
     * Unidades (das informadas) com morador ativo e unidades ativas do condomínio.
     *
     * @param list<int> $units
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function units(int $condominium, array $units): array
    {
        if ($units === []) {
            return [[], []];
        }
        $in = implode(',', array_fill(0, count($units), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT unit_id FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND (ends_on IS NULL OR ends_on >= ?) AND unit_id IN ({$in})");
        $stmt->execute(array_merge([$condominium, date('Y-m-d')], $units));
        $inhabited = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND id IN ({$in})");
        $stmt->execute(array_merge([$condominium], $units));
        $active = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        return [$inhabited, $active];
    }

    /**
     * Dados do formulário a partir da linha do cadastro.
     *
     * @param list<string> $fields
     * @return array<string, ?string>
     */
    private static function formData(array $fields, array $row): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $row[$field] === null ? null : (string)$row[$field];
        }
        return $data;
    }

    /** @param array<string, mixed> $input */
    public function release(User $user, array $input): array
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
        if (array_key_exists('vehicle_ids', $input) && $input['vehicle_ids'] !== null) {
            if (!is_array($input['vehicle_ids']) || $input['vehicle_ids'] === []) {
                throw new InvalidArgumentException('Veículos: informe a lista de ids ou deixe vazio para todos os de unidades sem morador.');
            }
            $ids = [];
            foreach ($input['vehicle_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Veículos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_VEHICLES) {
                throw new InvalidArgumentException(sprintf('Até %d veículos por vez.', self::MAX_VEHICLES));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $definition = RegistryService::definition('vehicles');
        $fields = array_keys($definition['fields']);
        $kinds = $definition['fields']['kind']['options'];
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'skipped_unlinked' => 0, 'skipped_inactive_unit' => 0,
            'skipped_invalid' => 0, 'vehicles' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_vehicles WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    ORDER BY id FOR UPDATE");
                $stmt->execute([(int)$condominium]);
                $found = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $linked = array_values(array_filter($found, static fn (array $r): bool => $r['unit_id'] !== null));
                $result['skipped_unlinked'] = count($found) - count($linked);
                [$inhabited, $active] = $this->units((int)$condominium, array_values(array_unique(array_map(static fn (array $r): int => (int)$r['unit_id'], $linked))));
                $rows = [];
                foreach ($linked as $r) {
                    $unit = (int)$r['unit_id'];
                    if (isset($inhabited[$unit])) {
                        continue;
                    }
                    if (!isset($active[$unit])) {
                        $result['skipped_inactive_unit']++;
                    } elseif (self::formError($definition, self::formData($fields, $r)) !== null) {
                        $result['skipped_invalid']++;
                    } else {
                        $rows[] = $r;
                    }
                }
                if (count($rows) > self::MAX_VEHICLES) {
                    throw new InvalidArgumentException(sprintf('Mais de %d veículos a inativar: inative em partes (escolha os ids).', self::MAX_VEHICLES));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_vehicles WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $units = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int)($r['unit_id'] ?? 0), $byId))));
                [$inhabited, $active] = $this->units((int)$condominium, $units);
                $rows = [];
                foreach ($ids as $id) {
                    $row = $byId[$id] ?? null;
                    $unit = (int)($row['unit_id'] ?? 0);
                    $formError = $row === null ? null : self::formError($definition, self::formData($fields, $row));
                    $problem = self::problem($row, isset($inhabited[$unit]), isset($active[$unit]), $formError);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_vehicle' => (string)$id]);
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                try {
                    $registry->save($user, 'vehicles', array_merge(self::formData($fields, $row), ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_vehicle' => (string)$row['id']]);
                }
                $result['vehicles'][] = ['id' => (int)$row['id'], 'kind' => (string)$row['kind']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['vehicles'] as $v) {
                Audit::record('deactivate_unattached', 'erp_vehicles', $v['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'kind' => $v['kind'], 'reason' => $reason, 'batch' => count($result['vehicles'])]);
            }
        }
        $byKind = array_fill_keys(array_keys($kinds), 0);
        foreach ($result['vehicles'] as $v) {
            $byKind[$v['kind']] = ($byKind[$v['kind']] ?? 0) + 1;
        }
        $result['count'] = count($result['vehicles']);
        $result['by_kind'] = $byKind;
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
