<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Qualidade do cadastro do condomínio (ADR 0185), somente leitura: o que falta ou está errado nas unidades e nos
 * vínculos e atrapalha a cobrança, o rateio e as declarações. Serve para a implantação e para a conferência periódica.
 *
 * Erro (a cobrança ou o rateio saem errados):
 * - charged_without_owner: unidade com conta a receber válida e sem proprietário ativo;
 * - fraction_sum: todas as unidades têm fração ideal, mas a soma não dá 1 (tolerância params.fraction_tolerance);
 * - document_invalid: proprietário, inquilino ou responsável financeiro com CPF/CNPJ preenchido e inválido;
 * - duplicate_unit_label: duas unidades ativas com o mesmo rótulo.
 * Aviso:
 * - units_without_owner: unidade ativa sem proprietário ativo;
 * - fraction_missing: parte das unidades com fração ideal e parte sem;
 * - document_missing: proprietário ou responsável financeiro sem CPF/CNPJ.
 * Informação: fractions_not_set, quando nenhuma unidade tem fração ideal (o condomínio rateia igual).
 *
 * Os itens de cada conferência trazem o rótulo da unidade e o id do vínculo, nunca o nome nem o documento. Até
 * MAX_ITEMS itens por conferência; a contagem é sempre a total. Escopo (ADR-002): um condomínio, obrigatório; fora do
 * escopo = CondominiumAccessDenied (404).
 */
final class RegistryHealth
{
    public const MAX_ITEMS = 50;
    public const ERRORS = ['charged_without_owner', 'fraction_sum', 'document_invalid', 'duplicate_unit_label'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Regra pura.
     *
     * @param list<array{id:int, label:string, ideal_fraction:?float}> $units unidades ativas
     * @param list<int> $ownerUnitIds unidades com proprietário ativo
     * @param list<int> $chargedUnitIds unidades com conta a receber válida
     * @param list<array{id:int, unit_id:?int, unit_label:string, relationship:string, document:?string}> $people vínculos ativos
     * @return array{ok:bool, errors:int, warnings:int, summary:array<string,int>, checks:list<array>}
     */
    public static function evaluate(array $units, array $ownerUnitIds, array $chargedUnitIds, array $people, float $tolerance): array
    {
        $checks = [];
        $add = static function (string $code, string $level, string $message, array $items) use (&$checks): void {
            if ($items !== []) {
                $checks[] = ['code' => $code, 'level' => $level, 'message' => $message, 'count' => count($items), 'items' => array_slice($items, 0, self::MAX_ITEMS)];
            }
        };
        $owners = array_flip($ownerUnitIds);
        $labels = array_column($units, 'label', 'id');
        $withoutOwner = array_values(array_filter($units, static fn (array $u): bool => !isset($owners[$u['id']])));
        $charged = array_values(array_filter($withoutOwner, static fn (array $u): bool => in_array($u['id'], $chargedUnitIds, true)));
        $unitItem = static fn (array $u): array => ['unit_id' => $u['id'], 'unit_label' => $u['label']];
        $add('charged_without_owner', 'error', 'Unidades cobradas sem proprietário ativo: a cobrança e as declarações ficam sem responsável.', array_map($unitItem, $charged));

        $byLabel = [];
        foreach ($units as $u) {
            $byLabel[mb_strtolower(trim($u['label']))][] = $u;
        }
        $duplicates = array_merge(...array_values(array_filter($byLabel, static fn (array $g): bool => count($g) > 1)) ?: [[]]);
        usort($duplicates, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $add('duplicate_unit_label', 'error', 'Unidades ativas com o mesmo rótulo: a cobrança e a importação não sabem qual é qual.', array_map($unitItem, $duplicates));

        $withFraction = array_values(array_filter($units, static fn (array $u): bool => $u['ideal_fraction'] !== null));
        if ($units !== [] && $withFraction === []) {
            $checks[] = ['code' => 'fractions_not_set', 'level' => 'info', 'message' => 'Nenhuma unidade tem fração ideal: o rateio é igual entre as unidades.',
                'count' => count($units), 'items' => []];
        } elseif (count($withFraction) < count($units)) {
            $add('fraction_missing', 'warning', 'Parte das unidades está sem fração ideal: o rateio pela fração fica incompleto.',
                array_map($unitItem, array_values(array_filter($units, static fn (array $u): bool => $u['ideal_fraction'] === null))));
        } else {
            $sum = array_sum(array_column($withFraction, 'ideal_fraction'));
            if (abs($sum - 1) > $tolerance) {
                $checks[] = ['code' => 'fraction_sum', 'level' => 'error', 'message' => 'A soma das frações ideais é ' . rtrim(rtrim(number_format($sum, 6, ',', ''), '0'), ',')
                    . ', e devia ser 1.', 'count' => 1, 'items' => []];
            }
        }

        $invalid = $missing = [];
        foreach ($people as $p) {
            $item = ['resident_id' => $p['id'], 'unit_id' => $p['unit_id'], 'unit_label' => $p['unit_id'] !== null ? ($labels[$p['unit_id']] ?? $p['unit_label']) : $p['unit_label'],
                'relationship' => $p['relationship']];
            $document = trim((string)($p['document'] ?? ''));
            if ($document === '') {
                if (in_array($p['relationship'], ['owner', 'financial'], true)) {
                    $missing[] = $item;
                }
            } elseif (!InvitationRules::validDocument($document)) {
                $invalid[] = $item;
            }
        }
        $add('document_invalid', 'error', 'CPF/CNPJ preenchido e inválido: corrija no cadastro de proprietários e inquilinos.', $invalid);
        $add('units_without_owner', 'warning', 'Unidades ativas sem proprietário ativo.', array_map($unitItem, $withoutOwner));
        $add('document_missing', 'warning', 'Proprietário ou responsável financeiro sem CPF/CNPJ: a declaração e a cobrança registrada precisam dele.', $missing);

        $count = static fn (string $level): int => count(array_filter($checks, static fn (array $c): bool => $c['level'] === $level));
        return ['ok' => $count('error') === 0, 'errors' => $count('error'), 'warnings' => $count('warning'),
            'summary' => ['units' => count($units), 'units_with_owner' => count($units) - count($withoutOwner), 'people' => count($people)],
            'checks' => $checks];
    }

    public function check(User $user, ?int $condominiumId): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $units = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'label' => (string)$r['label'],
            'ideal_fraction' => $r['ideal_fraction'] !== null ? (float)$r['ideal_fraction'] : null],
            $this->rows("SELECT id, label, ideal_fraction FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY id", [$condominiumId]));
        $owners = array_map('intval', array_column($this->rows("SELECT DISTINCT unit_id FROM erp_residents WHERE condominium_id = ? AND relationship = 'owner'
            AND status = 'active' AND deleted_at IS NULL AND unit_id IS NOT NULL", [$condominiumId]), 'unit_id'));
        $charged = array_map('intval', array_column($this->rows("SELECT DISTINCT unit_id FROM erp_entries WHERE condominium_id = ? AND nature = 'receita'
            AND status = 'active' AND deleted_at IS NULL AND unit_id IS NOT NULL", [$condominiumId]), 'unit_id'));
        $people = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'unit_id' => $r['unit_id'] !== null ? (int)$r['unit_id'] : null,
            'unit_label' => (string)$r['unit_label'], 'relationship' => (string)$r['relationship'], 'document' => $r['document']],
            $this->rows("SELECT id, unit_id, unit_label, relationship, document FROM erp_residents WHERE condominium_id = ? AND status = 'active'
                AND deleted_at IS NULL AND relationship IN ('owner','tenant','financial') ORDER BY unit_label, id", [$condominiumId]));
        try {
            $tolerance = (float)ErpSettings::value($this->pdo, null, 'params.fraction_tolerance');
        } catch (\Throwable) {
            $tolerance = 0.0001;   // padrão do AP-166 quando a configuração não está disponível
        }
        return ['condominium_id' => $condominiumId] + self::evaluate($units, $owners, $charged, $people, $tolerance);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
