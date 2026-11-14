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
 * Situação das portarias do condomínio (ADR 0294), somente leitura. O cadastro de portarias (AP-131) guarda o tipo
 * (presencial, remota ou híbrida), os turnos e a situação; os acessos (AP-133) e as encomendas (AP-135) apontam a portaria
 * em que foram registrados. O movimento da portaria (ADR 0230) resume os acessos por tipo de pessoa, mas ninguém vê, por
 * portaria, quantas estão ativas de cada tipo, quanto cada uma registrou no período, quais ficaram sem registro nenhum, e
 * quantas encomendas esperam retirada em cada uma (e desde quando).
 *
 * Reusa o que já existe: os cadastros do registro (RegistryService::definition: gatehouses para a tabela, a permissão e
 * os tipos; access_logs e deliveries para as tabelas e as permissões), e as regras das listas (sem os excluídos; acesso
 * cancelado fica de fora; encomenda aguardando retirada = status active, como no Registro de Entregas). O dia é o do banco.
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo do cadastro, sempre todos, com gatehouses (ativas), inactive, entries (acessos no período
 *   nas portarias ativas do tipo), pending_deliveries (encomendas aguardando retirada nelas) e oldest_pending_on; totals
 *   soma as linhas (oldest_pending_on é a mais antiga);
 * - elsewhere: acessos e encomendas sem portaria ou numa portaria inativa ou removida, à parte; all_entries e
 *   all_pending somam as linhas e o elsewhere;
 * - idle_ids: portarias ativas sem acesso no período, até MAX_IDS, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, contagens, datas e ids de portarias: nenhum nome de portaria, responsável, telefone, pessoa nem documento.
 */
final class GatehouseOverview
{
    public const MAX_ROWS = 5000;
    public const MAX_IDS = 200;
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array<string, string> os tipos de portaria do cadastro */
    public static function kinds(): array
    {
        return (array)RegistryService::definition('gatehouses')['fields']['kind']['options'];
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, kind:string, status:string}> $gatehouses
     * @param array<int|string, int> $entries acessos no período por portaria ('' = sem portaria)
     * @param array<int|string, array{count:int, oldest:string}> $pending encomendas aguardando retirada por portaria ('' = sem portaria)
     */
    public static function summarize(array $gatehouses, array $entries, array $pending): array
    {
        $kinds = self::kinds();
        $zero = ['gatehouses' => 0, 'inactive' => 0, 'entries' => 0, 'pending_deliveries' => 0, 'oldest_pending_on' => null];
        $counts = array_fill_keys(array_keys($kinds), $zero);
        $active = [];
        $idle = [];
        foreach ($gatehouses as $g) {
            if (!isset($counts[$g['kind']])) {
                continue;
            }
            if ($g['status'] !== 'active') {
                $counts[$g['kind']]['inactive']++;
                continue;
            }
            $counts[$g['kind']]['gatehouses']++;
            $active[$g['id']] = $g['kind'];
            $count = $entries[$g['id']] ?? 0;
            $counts[$g['kind']]['entries'] += $count;
            if ($count === 0) {
                $idle[] = $g['id'];
            }
            if (isset($pending[$g['id']])) {
                $counts[$g['kind']]['pending_deliveries'] += $pending[$g['id']]['count'];
                $oldest = $counts[$g['kind']]['oldest_pending_on'];
                $counts[$g['kind']]['oldest_pending_on'] = $oldest === null || $pending[$g['id']]['oldest'] < $oldest ? $pending[$g['id']]['oldest'] : $oldest;
            }
        }
        $elsewhere = ['entries' => 0, 'pending_deliveries' => 0, 'oldest_pending_on' => null];
        foreach ($entries as $id => $count) {
            if (!isset($active[$id])) {
                $elsewhere['entries'] += $count;
            }
        }
        foreach ($pending as $id => $p) {
            if (!isset($active[$id])) {
                $elsewhere['pending_deliveries'] += $p['count'];
                $elsewhere['oldest_pending_on'] = $elsewhere['oldest_pending_on'] === null || $p['oldest'] < $elsewhere['oldest_pending_on'] ? $p['oldest'] : $elsewhere['oldest_pending_on'];
            }
        }
        $rows = [];
        $totals = $zero;
        foreach ($kinds as $kind => $label) {
            $rows[] = ['kind' => $kind, 'kind_label' => $label] + $counts[$kind];
            foreach (['gatehouses', 'inactive', 'entries', 'pending_deliveries'] as $key) {
                $totals[$key] += $counts[$kind][$key];
            }
            $oldest = $counts[$kind]['oldest_pending_on'];
            if ($oldest !== null && ($totals['oldest_pending_on'] === null || $oldest < $totals['oldest_pending_on'])) {
                $totals['oldest_pending_on'] = $oldest;
            }
        }
        sort($idle);
        return ['rows' => $rows, 'totals' => $totals, 'elsewhere' => $elsewhere,
            'all_entries' => $totals['entries'] + $elsewhere['entries'], 'all_pending' => $totals['pending_deliveries'] + $elsewhere['pending_deliveries'],
            'idle_ids' => array_slice($idle, 0, self::MAX_IDS), 'idle_truncated' => count($idle) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, mixed $days = null, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $id = $int($condominiumId);
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $window = self::DEFAULT_DAYS;
        if ($days !== null && $days !== '') {
            $window = $int($days);
            if ($window === false || $window > self::MAX_DAYS) {
                throw new InvalidArgumentException(sprintf('Dias: de 1 a %d.', self::MAX_DAYS));
            }
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        // O dia do banco, para comparar com as datas gravadas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $from = (new \DateTimeImmutable($today))->modify(sprintf('-%d days', (int)$window - 1))->format('Y-m-d');
        $gatehouses = (string)RegistryService::definition('gatehouses')['table'];
        $logs = (string)RegistryService::definition('access_logs')['table'];
        $deliveries = (string)RegistryService::definition('deliveries')['table'];

        $stmt = $this->pdo->prepare("SELECT id, kind, status FROM {$gatehouses} WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d portarias: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT gatehouse_id, COUNT(*) AS n FROM {$logs} WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND entered_on BETWEEN ? AND ? GROUP BY gatehouse_id");
        $stmt->execute([(int)$id, $from, $today]);
        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $entries[$r['gatehouse_id'] === null ? '' : (int)$r['gatehouse_id']] = (int)$r['n'];
        }
        $stmt = $this->pdo->prepare("SELECT gatehouse_id, COUNT(*) AS n, MIN(received_on) AS oldest FROM {$deliveries} WHERE condominium_id = ? AND deleted_at IS NULL
            AND status = 'active' GROUP BY gatehouse_id");
        $stmt->execute([(int)$id]);
        $pending = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pending[$r['gatehouse_id'] === null ? '' : (int)$r['gatehouse_id']] = ['count' => (int)$r['n'], 'oldest' => substr((string)$r['oldest'], 0, 10)];
        }
        return ['condominium_id' => (int)$id, 'days' => (int)$window, 'from' => $from, 'to' => $today] + self::summarize(
            array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status']], $rows),
            $entries,
            $pending
        );
    }
}
