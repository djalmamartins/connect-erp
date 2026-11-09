<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Cadastro rápido de unidades e ajuste de fração ideal e área em grade (AP-006, ADR 0140).
 *
 *  - plan(): regra pura que gera a sequência (blocos × andares × unidades por andar), com andares e
 *    números pulados e unidades extras (coberturas, lojas). Número = andar + posição com N dígitos
 *    (andar 1, 8 por andar, 2 dígitos: 101 a 108); no térreo (andar 0) fica só a posição (01 a 08).
 *  - preview()/create(): mesma sequência com a marcação do que já existe no condomínio. Criar grava só
 *    as novas, pelo motor dos cadastros (RegistryService, mesmas validações e auditoria por unidade),
 *    em uma transação: ou todas entram, ou nenhuma.
 *  - measures()/saveMeasures(): fração ideal e área privativa de todas as unidades ativas do condomínio,
 *    gravadas de uma vez (só as que mudaram, cada uma auditada). A soma das frações não passa de 1 e,
 *    quando todas estão preenchidas, precisa dar 1 (100%), com a tolerância de params.fraction_tolerance.
 *  - distribute(): regra pura que reparte 1 (100%) igualmente ou pela área, em milionésimos, com soma
 *    exata (maiores restos).
 *
 * Escopo (ADR-002): o condomínio precisa estar no acesso do usuário; fora dele = RegistryNotFound (404).
 * Permissões (erp.units.view/manage) ficam nos controllers.
 */
final class UnitBatch
{
    public const MAX_UNITS = 2000;
    public const MAX_FLOOR = 300;
    public const MAX_PER_FLOOR = 99;
    public const TYPES = ['apartamento' => 'Apartamento', 'casa' => 'Casa', 'sala' => 'Sala comercial', 'loja' => 'Loja', 'garagem' => 'Garagem', 'outro' => 'Outro'];

    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Sequência de unidades a partir dos parâmetros (regra pura). Erros por campo em
     * RegistryValidationException.
     *
     * @return array{units: list<array{block:?string, number:string, floor:?string, label:string}>, options: array<string, mixed>}
     */
    public static function plan(array $input): array
    {
        $errors = [];
        $blocks = self::textList($input['blocks'] ?? null);
        foreach ($blocks as $block) {
            if (mb_strlen($block) > 20) {
                $errors['blocks'] = 'Cada bloco ou torre tem no máximo 20 caracteres.';
            }
        }
        if (count(array_unique(array_map('mb_strtolower', $blocks))) !== count($blocks)) {
            $errors['blocks'] = 'Há bloco ou torre repetido.';
        }
        $from = self::int($input['floor_from'] ?? null);
        $to = self::int($input['floor_to'] ?? null);
        $perFloor = self::int($input['units_per_floor'] ?? null);
        $first = self::int($input['first_position'] ?? 1);
        $digits = self::int($input['digits'] ?? 2);
        $extras = self::textList($input['extra_numbers'] ?? null);
        $hasFloors = $from !== null || $to !== null || $perFloor !== null;

        if ($hasFloors) {
            if ($from === null || $from < 0 || $from > self::MAX_FLOOR) {
                $errors['floor_from'] = 'Informe o andar inicial (0 = térreo, até ' . self::MAX_FLOOR . ').';
            }
            if ($to === null || $to < 0 || $to > self::MAX_FLOOR) {
                $errors['floor_to'] = 'Informe o andar final (até ' . self::MAX_FLOOR . ').';
            } elseif ($from !== null && $to < $from) {
                $errors['floor_to'] = 'O andar final não pode ser menor que o inicial.';
            }
            if ($perFloor === null || $perFloor < 1 || $perFloor > self::MAX_PER_FLOOR) {
                $errors['units_per_floor'] = 'Informe de 1 a ' . self::MAX_PER_FLOOR . ' unidades por andar.';
            }
            if ($first === null || $first < 0 || $first > 9) {
                $errors['first_position'] = 'A primeira posição vai de 0 a 9.';
            }
            if ($digits === null || $digits < 1 || $digits > 3) {
                $errors['digits'] = 'Use 1, 2 ou 3 dígitos para a posição.';
            } elseif ($perFloor !== null && $first !== null && $first + $perFloor - 1 >= 10 ** $digits) {
                $errors['digits'] = sprintf('Com %d por andar a posição passa de %d dígito(s). Aumente os dígitos.', $perFloor, $digits);
            }
        } elseif ($extras === []) {
            $errors['floor_from'] = 'Informe os andares e as unidades por andar, ou as unidades avulsas.';
        }
        foreach ($extras as $extra) {
            if (mb_strlen($extra) > 16) {
                $errors['extra_numbers'] = 'Cada unidade avulsa tem no máximo 16 caracteres.';
            }
        }
        $skipFloors = [];
        foreach (self::textList($input['skip_floors'] ?? null) as $floor) {
            $value = self::int($floor);
            if ($value === null || $value < 0) {
                $errors['skip_floors'] = "Andar a pular inválido: \"{$floor}\". Use números separados por vírgula.";
                continue;
            }
            $skipFloors[$value] = true;
        }
        $skipNumbers = [];
        foreach (self::textList($input['skip_numbers'] ?? null) as $number) {
            $skipNumbers[mb_strtolower($number)] = true;
        }
        if ($errors !== []) {
            throw new RegistryValidationException($errors);
        }

        $numbers = [];
        if ($hasFloors) {
            $estimate = max(1, count($blocks)) * ($to - $from + 1) * $perFloor;
            if ($estimate > self::MAX_UNITS * 2) {
                throw new RegistryValidationException(['units_per_floor' => 'São unidades demais de uma vez (máximo ' . self::MAX_UNITS . ').']);
            }
            for ($floor = $from; $floor <= $to; $floor++) {
                if (isset($skipFloors[$floor])) {
                    continue;
                }
                for ($position = $first; $position < $first + $perFloor; $position++) {
                    $suffix = str_pad((string)$position, $digits, '0', STR_PAD_LEFT);
                    $numbers[] = ['number' => $floor === 0 ? $suffix : $floor . $suffix, 'floor' => $floor === 0 ? 'Térreo' : (string)$floor];
                }
            }
        }
        foreach ($extras as $extra) {
            $numbers[] = ['number' => $extra, 'floor' => null];
        }

        $units = [];
        $seen = [];
        foreach ($blocks === [] ? [null] : $blocks as $block) {
            foreach ($numbers as $n) {
                if (isset($skipNumbers[mb_strtolower($n['number'])])) {
                    continue;
                }
                $label = $block === null ? $n['number'] : $block . ' - ' . $n['number'];
                $key = mb_strtolower($label);
                if (isset($seen[$key])) {
                    continue;   // unidade avulsa igual a uma da sequência
                }
                $seen[$key] = true;
                $units[] = ['block' => $block, 'number' => $n['number'], 'floor' => $n['floor'], 'label' => $label];
            }
        }
        if ($units === []) {
            throw new RegistryValidationException(['floor_from' => 'Com esses parâmetros nenhuma unidade é gerada.']);
        }
        if (count($units) > self::MAX_UNITS) {
            throw new RegistryValidationException(['units_per_floor' => sprintf('São %d unidades; o máximo de uma vez é %d. Gere por bloco.', count($units), self::MAX_UNITS)]);
        }

        $type = (string)($input['type'] ?? 'apartamento');
        $area = self::decimal($input['private_area'] ?? null, 2);
        $options = ['type' => $type, 'private_area' => $area];
        if (!isset(self::TYPES[$type])) {
            throw new RegistryValidationException(['type' => 'Escolha o tipo da unidade.']);
        }
        if ($area === false || ($area !== null && ((float)$area < 0 || (float)$area > 99999999.99))) {
            throw new RegistryValidationException(['private_area' => 'Área inválida. Use número em m², ex.: 72,50.']);
        }
        return ['units' => $units, 'options' => $options];
    }

    /**
     * Pré-visualização: a sequência com o que já existe no condomínio (não é criado de novo).
     *
     * @return array{condominium_id:int, units: list<array>, total:int, new:int, existing:int, type:string, private_area:?string}
     */
    public function preview(User $user, array $input): array
    {
        $condominium = $this->condominium($user, $input);
        $plan = self::plan($input);
        $existing = $this->existingLabels($condominium);
        $units = array_map(static fn (array $u): array => $u + ['exists' => isset($existing[mb_strtolower($u['label'])])], $plan['units']);
        $new = count(array_filter($units, static fn (array $u): bool => !$u['exists']));
        return ['condominium_id' => $condominium, 'units' => $units, 'total' => count($units), 'new' => $new, 'existing' => count($units) - $new]
            + $plan['options'];
    }

    /**
     * Cria as unidades novas da sequência, todas ou nenhuma.
     *
     * @return array{created:int, skipped:int, ids: list<int>}
     */
    public function create(User $user, array $input): array
    {
        $preview = $this->preview($user, $input);
        $ids = [];
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            // Trava as unidades do condomínio: dois cadastros rápidos ao mesmo tempo não duplicam.
            $this->pdo->prepare('SELECT id FROM condominiums WHERE id = :c FOR UPDATE')->execute(['c' => $preview['condominium_id']]);
            $existing = $this->existingLabels($preview['condominium_id']);
            foreach ($preview['units'] as $unit) {
                if (isset($existing[mb_strtolower($unit['label'])])) {
                    continue;
                }
                $saved = $this->registry->save($user, 'units', [
                    'condominium_id' => $preview['condominium_id'], 'block' => $unit['block'], 'number' => $unit['number'],
                    'floor' => $unit['floor'], 'type' => $preview['type'], 'private_area' => $preview['private_area'], 'status' => 'active',
                ]);
                $ids[] = (int)$saved['id'];
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['created' => count($ids), 'skipped' => $preview['total'] - count($ids), 'ids' => $ids];
    }

    /**
     * Fração ideal e área de todas as unidades ativas do condomínio, com as somas.
     *
     * @return array{condominium_id:int, units: list<array>, fraction_sum: float, area_sum: float, missing_fraction:int, missing_area:int, complete: bool}
     */
    public function measures(User $user, int $condominiumId): array
    {
        if (!$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
        return self::summary($condominiumId, $this->activeUnits($condominiumId, false), $this->tolerance());
    }

    /**
     * Grava fração ideal e/ou área de várias unidades de uma vez. Cada item: {id, ideal_fraction?, private_area?};
     * campo ausente não muda, vazio limpa. Só as unidades que mudaram são gravadas e auditadas.
     *
     * @param list<array> $changes
     * @return array measures() depois da gravação + updated
     */
    public function saveMeasures(User $user, int $condominiumId, array $changes): array
    {
        if (!$this->scope->canAccess($user, $condominiumId)) {
            throw new RegistryNotFound();
        }
        if ($changes === [] || count($changes) > self::MAX_UNITS) {
            throw new RegistryValidationException(['units' => 'Envie de 1 a ' . self::MAX_UNITS . ' unidades.']);
        }
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $units = $this->activeUnits($condominiumId, true);
            $original = array_column($units, null, 'id');
            $byId = [];
            foreach ($units as $i => $unit) {
                $byId[$unit['id']] = $i;
            }
            $errors = [];
            $touched = [];
            foreach ($changes as $n => $change) {
                $id = is_array($change) ? self::int($change['id'] ?? null) : null;
                if ($id === null || !isset($byId[$id])) {
                    $errors["units.{$n}"] = 'Unidade não encontrada entre as ativas deste condomínio.';
                    continue;
                }
                $unit = &$units[$byId[$id]];
                if (array_key_exists('ideal_fraction', $change)) {
                    $fraction = self::fraction($change['ideal_fraction']);
                    if ($fraction === false) {
                        $errors["units.{$n}.ideal_fraction"] = "{$unit['label']}: fração inválida (maior que 0 e até 1, ex.: 0,012500 ou 1,25%).";
                    } elseif ($fraction !== $unit['ideal_fraction']) {
                        $unit['ideal_fraction'] = $fraction;
                        $touched[$id] = true;
                    }
                }
                if (array_key_exists('private_area', $change)) {
                    $area = self::decimal($change['private_area'], 2);
                    if ($area === false || ($area !== null && ((float)$area < 0 || (float)$area > 99999999.99))) {
                        $errors["units.{$n}.private_area"] = "{$unit['label']}: área inválida (m², ex.: 72,50).";
                    } elseif ($area !== $unit['private_area']) {
                        $unit['private_area'] = $area;
                        $touched[$id] = true;
                    }
                }
                unset($unit);
            }
            if ($errors === []) {
                $tolerance = $this->tolerance();
                $sum = self::summary($condominiumId, $units, $tolerance);
                if ($sum['fraction_sum'] > 1 + $tolerance) {
                    $errors['ideal_fraction'] = sprintf('A soma das frações ficaria %s%%, acima de 100%%.', self::percent($sum['fraction_sum']));
                } elseif ($sum['missing_fraction'] === 0 && abs($sum['fraction_sum'] - 1) > $tolerance) {
                    $errors['ideal_fraction'] = sprintf('Com todas as frações preenchidas, a soma precisa dar 100%%; está em %s%%.', self::percent($sum['fraction_sum']));
                }
            }
            if ($errors !== []) {
                throw new RegistryValidationException($errors);
            }
            $update = $this->pdo->prepare('UPDATE erp_units SET ideal_fraction = :f, private_area = :a, updated_by = :u WHERE id = :id AND deleted_at IS NULL');
            foreach ($units as $unit) {
                if (!isset($touched[$unit['id']])) {
                    continue;
                }
                $update->execute(['f' => $unit['ideal_fraction'], 'a' => $unit['private_area'], 'u' => (int)$user->id, 'id' => $unit['id']]);
                $before = $original[$unit['id']];
                Audit::record('update', 'erp_units', $unit['id'],
                    ['ideal_fraction' => $before['ideal_fraction'], 'private_area' => $before['private_area']],
                    ['ideal_fraction' => $unit['ideal_fraction'], 'private_area' => $unit['private_area']]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->measures($user, $condominiumId) + ['updated' => count($touched)];
    }

    /**
     * Reparte 1 (100%) entre as unidades (regra pura), em milionésimos e com soma exata.
     * 'equal' = partes iguais; 'area' = proporcional à área privativa (exige área em todas).
     *
     * @param list<array{id:int, private_area?:mixed}> $units
     * @return array<int, string> id => fração com 6 casas
     */
    public static function distribute(array $units, string $mode): array
    {
        if ($units === []) {
            throw new RegistryValidationException(['units' => 'O condomínio não tem unidades ativas.']);
        }
        $weights = [];
        foreach ($units as $unit) {
            $id = (int)($unit['id'] ?? 0);
            if ($mode === 'equal') {
                $weights[$id] = 1.0;
                continue;
            }
            if ($mode !== 'area') {
                throw new RegistryValidationException(['mode' => 'Escolha dividir em partes iguais ou pela área.']);
            }
            $area = self::decimal($unit['private_area'] ?? null, 2);
            if ($area === null || $area === false || (float)$area <= 0) {
                throw new RegistryValidationException(['private_area' => 'Para dividir pela área, preencha a área de todas as unidades.']);
            }
            $weights[$id] = (float)$area;
        }
        $total = array_sum($weights);
        $scale = 1000000;
        $shares = [];
        $remainders = [];
        foreach ($weights as $id => $weight) {
            $exact = $weight / $total * $scale;
            $shares[$id] = (int)floor($exact + 1e-9);
            $remainders[$id] = $exact - $shares[$id];
        }
        $left = $scale - array_sum($shares);
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $id) {
            if ($left <= 0) {
                break;
            }
            $shares[$id]++;
            $left--;
        }
        return array_map(static fn (int $share): string => number_format($share / $scale, 6, '.', ''), $shares);
    }

    /** @param list<array> $units */
    public static function summary(int $condominiumId, array $units, float $tolerance): array
    {
        $fractions = array_filter(array_column($units, 'ideal_fraction'), static fn ($v): bool => $v !== null);
        $areas = array_filter(array_column($units, 'private_area'), static fn ($v): bool => $v !== null);
        $sum = round(array_sum(array_map('floatval', $fractions)), 6);
        return [
            'condominium_id' => $condominiumId,
            'units' => array_map(static fn (array $u): array => [
                'id' => $u['id'], 'label' => $u['label'], 'block' => $u['block'], 'number' => $u['number'], 'floor' => $u['floor'], 'type' => $u['type'],
                'ideal_fraction' => $u['ideal_fraction'] === null ? null : (float)$u['ideal_fraction'],
                'private_area' => $u['private_area'] === null ? null : (float)$u['private_area'],
            ], $units),
            'fraction_sum' => $sum,
            'area_sum' => round(array_sum(array_map('floatval', $areas)), 2),
            'missing_fraction' => count($units) - count($fractions),
            'missing_area' => count($units) - count($areas),
            'complete' => $units !== [] && count($fractions) === count($units) && abs($sum - 1) <= $tolerance,
        ];
    }

    /** Tolerância da soma das frações (AP-166, params.fraction_tolerance), a mesma do AP-167 e da importação. */
    private function tolerance(): float
    {
        return (float)ErpSettings::value($this->pdo, null, 'params.fraction_tolerance');
    }

    /** @return list<array{id:int, label:string, block:?string, number:string, floor:?string, type:string, ideal_fraction:?string, private_area:?string}> */
    private function activeUnits(int $condominiumId, bool $lock): array
    {
        $stmt = $this->pdo->prepare("SELECT id, label, block, number, floor, type, ideal_fraction, private_area FROM erp_units
            WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active'
            ORDER BY COALESCE(block, ''), CHAR_LENGTH(number), number, id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['c' => $condominiumId]);
        return array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'label' => (string)$r['label'], 'block' => $r['block'] !== null && $r['block'] !== '' ? (string)$r['block'] : null,
            'number' => (string)$r['number'], 'floor' => $r['floor'] !== null ? (string)$r['floor'] : null, 'type' => (string)$r['type'],
            'ideal_fraction' => $r['ideal_fraction'] !== null ? number_format((float)$r['ideal_fraction'], 6, '.', '') : null,
            'private_area' => $r['private_area'] !== null ? number_format((float)$r['private_area'], 2, '.', '') : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Rótulos (minúsculos) das unidades não excluídas do condomínio, ativas ou inativas (a regra de unicidade vale para as duas). */
    private function existingLabels(int $condominiumId): array
    {
        $stmt = $this->pdo->prepare('SELECT label FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL');
        $stmt->execute(['c' => $condominiumId]);
        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $label) {
            $labels[mb_strtolower((string)$label)] = true;
        }
        return $labels;
    }

    private function condominium(User $user, array $input): int
    {
        $id = self::int($input['condominium_id'] ?? null);
        if ($id === null || $id <= 0) {
            throw new RegistryValidationException(['condominium_id' => 'Escolha o condomínio.']);
        }
        if (!$this->scope->canAccess($user, $id)) {
            throw new RegistryNotFound();
        }
        return $id;
    }

    /** Lista de textos: array ou texto separado por vírgula, ponto e vírgula ou quebra de linha. @return list<string> */
    private static function textList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $text = trim(preg_replace('/\s+/u', ' ', (string)$item) ?? '');
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }
        return array_values($out);
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d{1,6}\s*$/', $value)) {
            return (int)trim($value);
        }
        return null;
    }

    /** Decimal com N casas ("72,5" → "72.50"); vazio = null; inválido = false. */
    private static function decimal(mixed $value, int $scale): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);   // 1.234,56 → 1234.56
        }
        if (!preg_match('/^\d{1,9}(\.\d+)?$/', $value)) {
            return false;
        }
        return number_format(round((float)$value, $scale), $scale, '.', '');
    }

    /** Fração: 0,0125 ou 1,25% → "0.012500"; vazio = null; fora de (0, 1] = false. */
    private static function fraction(mixed $value): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = sprintf('%.10F', $value);
        }
        if (!is_string($value)) {
            return false;
        }
        return Imports\ImportRules::fraction($value) ?? false;
    }

    private static function percent(float $fraction): string
    {
        return number_format($fraction * 100, 4, ',', '.');
    }
}
