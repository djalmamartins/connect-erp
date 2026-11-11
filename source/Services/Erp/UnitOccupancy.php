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
 * Perfil de ocupação das unidades do condomínio numa data (ADR 0240), somente leitura. O relatório das unidades
 * (RegistryReports::units) lista unidade por unidade, a qualidade do cadastro (RegistryHealth, ADR 0185) aponta o que falta
 * (unidade sem proprietário, documento inválido) e o painel da unidade mostra uma de cada vez. Ninguém vê o perfil do
 * condomínio: quantas unidades estão alugadas, quantas têm morador sem inquilino, quantas só têm o proprietário
 * cadastrado e quantas estão sem nenhum vínculo, por tipo de unidade. A administradora usa isso para assembleia
 * (quem vota e quem só mora), para a portaria e para conferir o cadastro depois de mudanças.
 *
 * Reusa o que já existe: os tipos de unidade e os vínculos do cadastro (RegistryService::definition: units e residents) e
 * a regra das listas (só ativos, sem excluídos). Um vínculo vale na data on quando está ativo e o início (se houver) não
 * passou de on e o fim (se houver) não é anterior a on.
 *
 * Regra pura classify() e summarize(), por unidade ativa:
 * - rented: tem inquilino;
 * - occupied: sem inquilino e com morador;
 * - owner_only: sem inquilino nem morador e com proprietário;
 * - other_links: só responsável financeiro;
 * - no_links: nenhum vínculo valendo.
 * Uma linha por situação, sempre as cinco, com units e by_type (os tipos do cadastro) somando units; totals soma as
 * linhas e é o número de unidades ativas. links conta os vínculos valendo por tipo de vínculo (somando linked), e
 * unlinked conta os que não apontam para uma unidade ativa (só o texto da unidade, cadastro antigo).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * situações, os tipos e as contagens: nenhum nome, documento, contato, rótulo de unidade ou observação.
 */
final class UnitOccupancy
{
    public const MAX_ROWS = 100000;
    public const SITUATIONS = [
        'rented' => 'Alugada (com inquilino)',
        'occupied' => 'Com morador, sem inquilino',
        'owner_only' => 'Só o proprietário cadastrado',
        'other_links' => 'Só responsável financeiro',
        'no_links' => 'Sem vínculo',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação de uma unidade pelos tipos de vínculo valendo (regra pura). @param list<string> $relationships */
    public static function classify(array $relationships): string
    {
        return match (true) {
            in_array('tenant', $relationships, true) => 'rented',
            in_array('resident', $relationships, true) => 'occupied',
            in_array('owner', $relationships, true) => 'owner_only',
            $relationships !== [] => 'other_links',
            default => 'no_links',
        };
    }

    /** Vínculo valendo na data (regra pura). */
    public static function valid(array $link, string $on): bool
    {
        $starts = substr((string)($link['starts_on'] ?? ''), 0, 10);
        $ends = substr((string)($link['ends_on'] ?? ''), 0, 10);
        return ($link['status'] ?? '') === 'active' && ($starts === '' || $starts <= $on) && ($ends === '' || $ends >= $on);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, type:string}> $units unidades ativas
     * @param list<array{unit_id:?int, relationship:string, status:string, starts_on:?string, ends_on:?string}> $links
     */
    public static function summarize(array $units, array $links, string $on): array
    {
        $types = array_keys(RegistryService::definition('units')['fields']['type']['options']);
        $relationshipOptions = RegistryService::definition('residents')['fields']['relationship']['options'];
        $byUnit = [];
        $linkCounts = array_fill_keys(array_keys($relationshipOptions), 0);
        $unlinked = 0;
        $active = [];
        foreach ($units as $unit) {
            $active[(int)$unit['id']] = true;
        }
        foreach ($links as $link) {
            if (!self::valid($link, $on) || !isset($linkCounts[$link['relationship']])) {
                continue;
            }
            $unit = $link['unit_id'] !== null ? (int)$link['unit_id'] : null;
            if ($unit === null || !isset($active[$unit])) {
                $unlinked++;
                continue;
            }
            $linkCounts[$link['relationship']]++;
            $byUnit[$unit][$link['relationship']] = true;
        }
        $rows = [];
        foreach (self::SITUATIONS as $situation => $label) {
            $rows[$situation] = ['situation' => $situation, 'situation_label' => $label, 'units' => 0, 'by_type' => array_fill_keys($types, 0)];
        }
        foreach ($units as $unit) {
            $situation = self::classify(array_keys($byUnit[(int)$unit['id']] ?? []));
            $type = in_array($unit['type'], $types, true) ? $unit['type'] : 'outro';
            $rows[$situation]['units']++;
            $rows[$situation]['by_type'][$type]++;
        }
        $totals = ['units' => 0, 'by_type' => array_fill_keys($types, 0)];
        foreach ($rows as $row) {
            $totals['units'] += $row['units'];
            foreach ($types as $type) {
                $totals['by_type'][$type] += $row['by_type'][$type];
            }
        }
        $linkRows = [];
        foreach ($relationshipOptions as $relationship => $label) {
            $linkRows[] = ['relationship' => $relationship, 'relationship_label' => $label, 'links' => $linkCounts[$relationship]];
        }
        return ['rows' => array_values($rows), 'totals' => $totals,
            'links' => ['rows' => $linkRows, 'linked' => array_sum($linkCounts), 'unlinked' => $unlinked]];
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

        $stmt = $this->pdo->prepare("SELECT id, type FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT "
            . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare('SELECT unit_id, relationship, status, starts_on, ends_on FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL
            ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($units) > self::MAX_ROWS || count($links) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d registros: fale com o suporte.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize(
            array_map(static fn (array $u): array => ['id' => (int)$u['id'], 'type' => (string)$u['type']], $units),
            array_map(static fn (array $l): array => ['unit_id' => $l['unit_id'] !== null ? (int)$l['unit_id'] : null, 'relationship' => (string)$l['relationship'],
                'status' => (string)$l['status'], 'starts_on' => $l['starts_on'] !== null ? substr((string)$l['starts_on'], 0, 10) : null,
                'ends_on' => $l['ends_on'] !== null ? substr((string)$l['ends_on'], 0, 10) : null], $links),
            $on);
    }
}
