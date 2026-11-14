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

/**
 * Cobertura das tarifas de consumo do condomínio numa data (ADR 0258), somente leitura. O cadastro de tarifas (AP-043)
 * guarda as faixas progressivas por tipo e vigência, do condomínio ou o padrão da Connect; as leituras (MeterReadings)
 * usam, para cada leitura, as faixas vigentes. Ninguém confere, porém, antes de lançar as leituras do mês, se cada tipo
 * de relógio do condomínio tem tarifa vigente, de onde ela vem (do condomínio ou o padrão), desde quando vale, se a
 * última faixa não tem limite (o consumo acima dela fica sem preço), se há buraco entre as faixas, e se já há reajuste
 * cadastrado para depois da data.
 *
 * Reusa o que já existe: os tipos do cadastro (RegistryService::definition('tariffs')) e a mesma regra das leituras
 * (MeterReadings::bands): a tarifa do condomínio com a vigência mais recente até a data; sem ela, a padrão; só as ativas
 * e sem excluídas. Os relógios contados são os ativos do condomínio, como na leitura.
 *
 * Regra pura summarize(), uma linha por tipo do cadastro, sempre todos:
 * - meters: relógios ativos do tipo;
 * - source (condominium, default ou null), valid_from e bands: a tarifa vigente em on e quantas faixas;
 * - starts_at_zero: a primeira faixa começa em 0; open_ended: alguma faixa sem limite (to_amount vazio);
 * - gaps: buracos entre faixas seguidas (a seguinte começa depois do fim da anterior);
 * - next_valid_from: a próxima vigência já cadastrada depois de on, da mesma origem (null = nenhuma);
 * - missing: há relógio do tipo e nenhuma tarifa vigente.
 * totals soma meters e bands das linhas; missing_kinds lista os tipos em falta.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * tipos, as contagens, as datas e as marcas de conferência: nenhum número de relógio, unidade, nome de faixa ou
 * observação.
 */
final class TariffCoverage
{
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
     * @param array<string, int> $meters relógios ativos por tipo
     * @param list<array{owner:string, kind:string, valid_from:string, from_amount:float, to_amount:?float}> $tariffs ativas, owner condominium ou default
     */
    public static function summarize(array $meters, array $tariffs, string $on): array
    {
        $rows = [];
        foreach (RegistryService::definition('tariffs')['fields']['kind']['options'] as $kind => $label) {
            $byOwner = ['condominium' => [], 'default' => []];
            foreach ($tariffs as $t) {
                if ($t['kind'] === $kind && isset($byOwner[$t['owner']])) {
                    $byOwner[$t['owner']][] = $t;
                }
            }
            $source = null;
            $validFrom = null;
            foreach (['condominium', 'default'] as $owner) {
                $dates = array_filter(array_map(static fn (array $t): string => substr($t['valid_from'], 0, 10), $byOwner[$owner]), static fn (string $d): bool => $d <= $on);
                if ($dates !== []) {
                    [$source, $validFrom] = [$owner, max($dates)];
                    break;
                }
            }
            $bands = $source === null ? [] : array_values(array_filter($byOwner[$source], static fn (array $t): bool => substr($t['valid_from'], 0, 10) === $validFrom));
            usort($bands, static fn (array $a, array $b): int => $a['from_amount'] <=> $b['from_amount']);
            $gaps = 0;
            for ($i = 1; $i < count($bands); $i++) {
                $previous = $bands[$i - 1]['to_amount'];
                if ($previous !== null && $bands[$i]['from_amount'] > $previous) {
                    $gaps++;
                }
            }
            $nextOwner = $source ?? ($byOwner['condominium'] !== [] ? 'condominium' : 'default');
            $future = array_filter(array_map(static fn (array $t): string => substr($t['valid_from'], 0, 10), $byOwner[$nextOwner]), static fn (string $d): bool => $d > $on);
            $count = (int)($meters[$kind] ?? 0);
            $rows[] = ['kind' => $kind, 'kind_label' => $label, 'meters' => $count, 'source' => $source, 'valid_from' => $validFrom, 'bands' => count($bands),
                'starts_at_zero' => $bands !== [] && (float)$bands[0]['from_amount'] === 0.0,
                'open_ended' => $bands !== [] && in_array(null, array_column($bands, 'to_amount'), true), 'gaps' => $gaps,
                'next_valid_from' => $future === [] ? null : min($future), 'missing' => $count > 0 && $source === null];
        }
        return ['rows' => $rows, 'totals' => ['meters' => array_sum(array_column($rows, 'meters')), 'bands' => array_sum(array_column($rows, 'bands'))],
            'missing_kinds' => array_values(array_map(static fn (array $r): string => $r['kind'], array_filter($rows, static fn (array $r): bool => $r['missing'])))];
    }

    public function build(User $user, mixed $condominiumId, mixed $on = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $on = $on === null || $on === '' ? date('Y-m-d') : $on;
        $date = is_string($on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare("SELECT kind, COUNT(*) AS meters FROM erp_meters WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' GROUP BY kind");
        $stmt->execute([(int)$id]);
        $meters = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $meters[(string)$r['kind']] = (int)$r['meters'];
        }
        $stmt = $this->pdo->prepare("SELECT condominium_id, kind, valid_from, from_amount, to_amount FROM erp_tariffs
            WHERE (condominium_id = ? OR condominium_id IS NULL) AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute([(int)$id]);
        $tariffs = array_map(static fn (array $r): array => ['owner' => $r['condominium_id'] === null ? 'default' : 'condominium', 'kind' => (string)$r['kind'],
            'valid_from' => substr((string)$r['valid_from'], 0, 10), 'from_amount' => (float)$r['from_amount'],
            'to_amount' => $r['to_amount'] === null ? null : (float)$r['to_amount']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize($meters, $tariffs, $on);
    }
}
