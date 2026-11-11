<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Resumo dos animais de estimação e dos veículos cadastrados no condomínio (ADR 0236), somente leitura. Os cadastros
 * (AP-011 veículos e AP-013 animais) listam registro por registro, com dono, placa e unidade; o painel da unidade
 * (PanelSummaries) conta só os de uma unidade. O síndico e a administradora querem a foto do condomínio: quantos animais
 * de cada espécie e porte, quantos com a vacinação em dia, quantos veículos de cada tipo, quantos sem vaga informada, e
 * quantas unidades têm um, dois, três ou mais, para regras de convivência, garagem e campanhas de vacinação.
 *
 * Reusa o que já existe: as opções do cadastro (RegistryService::definition: espécie, porte e tipo de veículo) e a regra
 * das listas (só os ativos, sem os excluídos). A unidade é a vinculada (unit_id) ou, sem vínculo, o texto da unidade,
 * só para contar unidades distintas; ela não sai na resposta.
 *
 * Regra pura summarize():
 * - pets.rows: uma linha por espécie (sempre as do cadastro), com pets, o porte (pequeno, médio, grande, sem porte) somando
 *   pets, vaccinated e not_vaccinated somando pets; pets.totals é a soma das linhas;
 * - vehicles.rows: uma linha por tipo, com vehicles, with_spot e without_spot somando vehicles; vehicles.totals é a soma;
 * - units: unidades ativas do condomínio, e, para animais e para veículos, quantas unidades têm 1, 2 ou 3 ou mais (somando
 *   as unidades com registro).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * opções e as contagens: nenhum nome, raça, placa, modelo, cor, vaga, dono, tutor, unidade ou observação.
 */
final class PetsVehiclesSummary
{
    public const MAX_ROWS = 50000;
    public const SIZES = ['pequeno', 'medio', 'grande', 'sem_porte'];
    public const SPREAD = ['um' => 1, 'dois' => 2, 'tres_ou_mais' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Chave da unidade só para contar unidades distintas: o vínculo ou o texto normalizado. */
    public static function unitKey(?int $unitId, ?string $label): ?string
    {
        if ($unitId !== null && $unitId > 0) {
            return 'u' . $unitId;
        }
        $text = mb_strtolower(trim((string)preg_replace('/\s+/', ' ', (string)$label)));
        return $text !== '' ? 't' . $text : null;
    }

    /** @param array<string, int> $perUnit */
    private static function spread(array $perUnit): array
    {
        $spread = array_fill_keys(array_keys(self::SPREAD), 0);
        foreach ($perUnit as $count) {
            $spread[$count >= 3 ? 'tres_ou_mais' : ($count === 2 ? 'dois' : 'um')]++;
        }
        return $spread + ['units' => array_sum($spread)];
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{species:string, size:?string, vaccinated:bool, unit:?string}> $pets
     * @param list<array{kind:string, has_spot:bool, unit:?string}> $vehicles
     */
    public static function summarize(array $pets, array $vehicles, int $unitsTotal): array
    {
        $petFields = RegistryService::definition('pets')['fields'];
        $vehicleFields = RegistryService::definition('vehicles')['fields'];
        $species = $petFields['species']['options'];
        $petRows = [];
        foreach ($species as $key => $label) {
            $petRows[$key] = ['species' => $key, 'species_label' => $label, 'pets' => 0, 'by_size' => array_fill_keys(self::SIZES, 0), 'vaccinated' => 0,
                'not_vaccinated' => 0];
        }
        $petUnits = [];
        foreach ($pets as $pet) {
            $key = isset($petRows[$pet['species']]) ? $pet['species'] : 'outro';
            $size = in_array($pet['size'], ['pequeno', 'medio', 'grande'], true) ? $pet['size'] : 'sem_porte';
            $petRows[$key]['pets']++;
            $petRows[$key]['by_size'][$size]++;
            $petRows[$key][$pet['vaccinated'] ? 'vaccinated' : 'not_vaccinated']++;
            if ($pet['unit'] !== null) {
                $petUnits[$pet['unit']] = ($petUnits[$pet['unit']] ?? 0) + 1;
            }
        }
        $petTotals = ['pets' => 0, 'by_size' => array_fill_keys(self::SIZES, 0), 'vaccinated' => 0, 'not_vaccinated' => 0];
        foreach ($petRows as $row) {
            foreach (['pets', 'vaccinated', 'not_vaccinated'] as $field) {
                $petTotals[$field] += $row[$field];
            }
            foreach (self::SIZES as $size) {
                $petTotals['by_size'][$size] += $row['by_size'][$size];
            }
        }
        $kinds = $vehicleFields['kind']['options'];
        $vehicleRows = [];
        foreach ($kinds as $key => $label) {
            $vehicleRows[$key] = ['kind' => $key, 'kind_label' => $label, 'vehicles' => 0, 'with_spot' => 0, 'without_spot' => 0];
        }
        $vehicleUnits = [];
        foreach ($vehicles as $vehicle) {
            $key = isset($vehicleRows[$vehicle['kind']]) ? $vehicle['kind'] : 'outro';
            $vehicleRows[$key]['vehicles']++;
            $vehicleRows[$key][$vehicle['has_spot'] ? 'with_spot' : 'without_spot']++;
            if ($vehicle['unit'] !== null) {
                $vehicleUnits[$vehicle['unit']] = ($vehicleUnits[$vehicle['unit']] ?? 0) + 1;
            }
        }
        $vehicleTotals = ['vehicles' => 0, 'with_spot' => 0, 'without_spot' => 0];
        foreach ($vehicleRows as $row) {
            foreach (array_keys($vehicleTotals) as $field) {
                $vehicleTotals[$field] += $row[$field];
            }
        }
        return [
            'pets' => ['rows' => array_values($petRows), 'totals' => $petTotals],
            'vehicles' => ['rows' => array_values($vehicleRows), 'totals' => $vehicleTotals],
            'units' => ['active_units' => $unitsTotal, 'with_pets' => self::spread($petUnits), 'with_vehicles' => self::spread($vehicleUnits)],
        ];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare("SELECT species, size, vaccinated, unit_id, unit_label FROM erp_pets WHERE condominium_id = ? AND deleted_at IS NULL
            AND status = 'active' ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare("SELECT kind, parking_spot IS NOT NULL AND TRIM(parking_spot) <> '' AS has_spot, unit_id, unit_label FROM erp_vehicles
            WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($pets) > self::MAX_ROWS || count($vehicles) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d registros: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute([(int)$id]);
        $units = (int)$stmt->fetchColumn();
        $key = static fn (array $r): ?string => self::unitKey($r['unit_id'] !== null ? (int)$r['unit_id'] : null, $r['unit_label'] !== null ? (string)$r['unit_label'] : null);
        return ['condominium_id' => (int)$id] + self::summarize(
            array_map(static fn (array $p): array => ['species' => (string)$p['species'], 'size' => $p['size'] !== null ? (string)$p['size'] : null,
                'vaccinated' => (bool)$p['vaccinated'], 'unit' => $key($p)], $pets),
            array_map(static fn (array $v): array => ['kind' => (string)$v['kind'], 'has_spot' => (bool)$v['has_spot'], 'unit' => $key($v)], $vehicles),
            $units);
    }
}
