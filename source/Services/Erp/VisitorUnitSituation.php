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
 * Visitantes do condomínio pela situação da unidade a que estão vinculados (ADR 0266), somente leitura. O relatório das
 * autorizações (ADR 0234) conta os visitantes pela data "autorizado até", e o perfil de ocupação (ADR 0240) conta as
 * unidades pelos vínculos; nenhum cruza os dois. Ninguém vê, de uma vez, quantos visitantes seguem "Liberado" em unidade
 * que já não tem morador ativo (a portaria deixa entrar quem foi autorizado por quem saiu), quantos estão em unidade
 * inativa e quantos têm só o texto da unidade, sem vínculo. O bloqueio em lote (ADR 0265) age sobre isso; este relatório
 * mostra o tamanho antes de agir.
 *
 * Reusa o que já existe: as situações do cadastro (RegistryService::definition('visitors'): Liberado e Bloqueado), a regra
 * de morador ativo do bloqueio em lote (ADR 0265: vínculo ativo, sem excluído e sem fim de vínculo passado) e a mesma ordem
 * de classificação, para que attention.blockable seja o que o bloqueio em lote pegaria hoje (sem ids). Sem os excluídos.
 *
 * Regra pura classify() e summarize(), por visitante:
 * - inhabited: unidade com morador ativo;
 * - empty: unidade ativa sem morador ativo (pede atenção quando há visitante liberado);
 * - inactive_unit: unidade inativa ou excluída, sem morador ativo;
 * - unlinked: só o texto da unidade, sem vínculo.
 * Uma linha por grupo, sempre as quatro, com units (unidades distintas com visitante; 0 em unlinked), visitors (= active +
 * blocked), active, permanent (liberados com acesso permanente) e blocked. totals soma as linhas. attention separa os
 * liberados de unidade sem morador em blockable (com data ou acesso permanente) e incomplete (sem os dois: o formulário
 * não regrava), somando empty_active. concentration conta as unidades vinculadas com visitante liberado pela quantidade
 * de liberados (um, de 2 a 5, mais de 5), somando units_with_active.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * grupos e as contagens: nenhum nome, documento, telefone, rótulo ou id de unidade, ou observação.
 */
final class VisitorUnitSituation
{
    public const MAX_ROWS = 50000;
    public const GROUPS = [
        'inhabited' => 'Unidade com morador ativo',
        'empty' => 'Unidade sem morador ativo',
        'inactive_unit' => 'Unidade inativa ou excluída',
        'unlinked' => 'Sem unidade vinculada (só o texto da unidade)',
    ];
    public const CONCENTRATION = ['um' => 1, 'de_2_a_5' => 5, 'mais_de_5' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Grupo do visitante pela unidade (regra pura, na ordem do bloqueio em lote). */
    public static function classify(?int $unit, bool $inhabited, bool $unitActive): string
    {
        return match (true) {
            $unit === null => 'unlinked',
            $inhabited => 'inhabited',
            !$unitActive => 'inactive_unit',
            default => 'empty',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{unit_id:?int, status:string, permanent_access:bool, valid_until:?string}> $visitors
     * @param array<int, true> $inhabited unidades com morador ativo
     * @param array<int, true> $activeUnits unidades ativas
     */
    public static function summarize(array $visitors, array $inhabited, array $activeUnits): array
    {
        $status = RegistryService::definition('visitors')['status'];
        $zero = ['visitors' => 0, 'active' => 0, 'permanent' => 0, 'blocked' => 0];
        $counts = array_fill_keys(array_keys(self::GROUPS), $zero);
        $units = array_fill_keys(array_keys(self::GROUPS), []);
        $perUnit = [];
        $attention = ['empty_active' => 0, 'blockable' => 0, 'incomplete' => 0];
        foreach ($visitors as $visitor) {
            $unit = $visitor['unit_id'];
            $group = self::classify($unit, $unit !== null && isset($inhabited[$unit]), $unit !== null && isset($activeUnits[$unit]));
            $active = $visitor['status'] === 'active';
            $counts[$group]['visitors']++;
            $counts[$group][$active ? 'active' : 'blocked']++;
            if ($unit !== null) {
                $units[$group][$unit] = true;
            }
            if (!$active) {
                continue;
            }
            if ($visitor['permanent_access']) {
                $counts[$group]['permanent']++;
            }
            if ($unit !== null) {
                $perUnit[$unit] = ($perUnit[$unit] ?? 0) + 1;
            }
            if ($group === 'empty') {
                $attention['empty_active']++;
                $complete = $visitor['permanent_access'] || substr((string)($visitor['valid_until'] ?? ''), 0, 10) !== '';
                $attention[$complete ? 'blockable' : 'incomplete']++;
            }
        }
        $rows = [];
        $totals = ['units' => 0] + $zero;
        foreach (self::GROUPS as $group => $label) {
            $row = ['group' => $group, 'group_label' => $label, 'units' => count($units[$group])] + $counts[$group];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
            $rows[] = $row;
        }
        $concentration = array_fill_keys(array_keys(self::CONCENTRATION), 0);
        foreach ($perUnit as $quantity) {
            $concentration[$quantity <= 1 ? 'um' : ($quantity <= 5 ? 'de_2_a_5' : 'mais_de_5')]++;
        }
        return ['status_labels' => ['active' => $status['active'], 'blocked' => $status['inactive']], 'rows' => $rows, 'totals' => $totals,
            'attention' => $attention, 'concentration' => $concentration + ['units_with_active' => count($perUnit)]];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT unit_id, status, permanent_access, valid_until FROM erp_visitors WHERE condominium_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($visitors) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d visitantes cadastrados: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT DISTINCT unit_id FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND (ends_on IS NULL OR ends_on >= ?) AND unit_id IS NOT NULL");
        $stmt->execute([(int)$id, date('Y-m-d')]);
        $inhabited = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute([(int)$id]);
        $activeUnits = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        return ['condominium_id' => (int)$id, 'on' => date('Y-m-d')] + self::summarize(array_map(static fn (array $v): array => [
            'unit_id' => $v['unit_id'] === null ? null : (int)$v['unit_id'], 'status' => (string)$v['status'], 'permanent_access' => (bool)$v['permanent_access'],
            'valid_until' => $v['valid_until'] !== null ? substr((string)$v['valid_until'], 0, 10) : null], $visitors), $inhabited, $activeUnits);
    }
}
