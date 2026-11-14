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
 * Cobertura das alíquotas de retenção do condomínio (ADR 0262), somente leitura. O cadastro de impostos (AP-164) guarda
 * as alíquotas de ISS, IRRF, INSS e PIS/COFINS/CSLL para conferir as notas de serviço: as do condomínio e o padrão da
 * Connect (sem condomínio). Ninguém confere, porém, antes de lançar uma nota, se cada imposto tem alíquota ativa para o
 * condomínio, de onde ela vem (do condomínio ou o padrão), se o condomínio substitui o padrão, se a alíquota cobre
 * serviços e materiais, e se há alíquotas diferentes para o mesmo imposto (o que pede atenção na conferência).
 *
 * Reusa o que já existe: os impostos e a aplicação do cadastro (RegistryService::definition('taxes')) e a regra das listas
 * do cadastro (o condomínio vê as próprias e as padrão; só as ativas e sem excluídas). Origem por imposto: as próprias do
 * condomínio, quando há; senão, as padrão.
 *
 * Regra pura summarize(), uma linha por imposto do cadastro, sempre todos:
 * - own, default e inactive_own: alíquotas ativas próprias, ativas padrão e inativas próprias;
 * - source (condominium, default ou null) e rates: a origem usada e quantas alíquotas dela;
 * - min_rate e max_rate: a menor e a maior alíquota da origem (null sem alíquota);
 * - covers_services e covers_materials: alguma alíquota da origem se aplica a serviços ou a materiais (todos vale para os
 *   dois);
 * - overrides_default: o condomínio tem alíquota própria e há padrão do mesmo imposto;
 * - missing: nenhuma alíquota ativa, nem própria nem padrão.
 * totals soma own, default, inactive_own e rates das linhas; missing_kinds lista os impostos em falta.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * impostos, as contagens, as alíquotas e as marcas de conferência: nenhum nome de alíquota ou observação.
 */
final class TaxCoverage
{
    public const COUNTS = ['own', 'default', 'inactive_own', 'rates'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{owner:string, kind:string, rate:float, applies_to:string, status:string}> $taxes owner condominium ou default
     */
    public static function summarize(array $taxes): array
    {
        $rows = [];
        foreach (RegistryService::definition('taxes')['fields']['kind']['options'] as $kind => $label) {
            $own = array_values(array_filter($taxes, static fn (array $t): bool => $t['kind'] === $kind && $t['owner'] === 'condominium' && $t['status'] === 'active'));
            $default = array_values(array_filter($taxes, static fn (array $t): bool => $t['kind'] === $kind && $t['owner'] === 'default' && $t['status'] === 'active'));
            $inactive = array_filter($taxes, static fn (array $t): bool => $t['kind'] === $kind && $t['owner'] === 'condominium' && $t['status'] !== 'active');
            [$source, $used] = match (true) {
                $own !== [] => ['condominium', $own],
                $default !== [] => ['default', $default],
                default => [null, []],
            };
            $rates = array_map(static fn (array $t): float => round($t['rate'], 2), $used);
            $applies = array_column($used, 'applies_to');
            $rows[] = ['kind' => $kind, 'kind_label' => $label, 'own' => count($own), 'default' => count($default), 'inactive_own' => count($inactive),
                'source' => $source, 'rates' => count($used), 'min_rate' => $rates === [] ? null : min($rates), 'max_rate' => $rates === [] ? null : max($rates),
                'covers_services' => array_intersect($applies, ['servicos', 'todos']) !== [], 'covers_materials' => array_intersect($applies, ['materiais', 'todos']) !== [],
                'overrides_default' => $own !== [] && $default !== [], 'missing' => $used === []];
        }
        $totals = [];
        foreach (self::COUNTS as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }
        return ['rows' => $rows, 'totals' => $totals,
            'missing_kinds' => array_values(array_map(static fn (array $r): string => $r['kind'], array_filter($rows, static fn (array $r): bool => $r['missing'])))];
    }

    public function build(User $user, mixed $condominiumId): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT condominium_id, kind, rate, applies_to, status FROM erp_taxes
            WHERE (condominium_id = ? OR condominium_id IS NULL) AND deleted_at IS NULL ORDER BY id');
        $stmt->execute([(int)$id]);
        $taxes = array_map(static fn (array $r): array => ['owner' => $r['condominium_id'] === null ? 'default' : 'condominium', 'kind' => (string)$r['kind'],
            'rate' => (float)$r['rate'], 'applies_to' => (string)$r['applies_to'], 'status' => (string)$r['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['condominium_id' => (int)$id] + self::summarize($taxes);
    }
}
