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
 * Situação dos banners do aplicativo para o condomínio (ADR 0286), somente leitura. Os banners (AP-156, ADR 0085) são
 * avisos curtos no topo do aplicativo, por período; sem condomínio, valem para todos. O cadastro lista um por um, e
 * ninguém vê, de uma vez, o que está no ar agora para um condomínio (os dele e os de todos), o que vai entrar, o que já
 * acabou mas continua ativo (esquecido) e o que acaba nos próximos dias.
 *
 * Reusa o que já existe: o cadastro de banners do registro (RegistryService::definition('banners'): a tabela, a
 * permissão erp.settings e o escopo opcional, em que sem condomínio vale para todos). As datas são do dia, no relógio do
 * banco.
 *
 * Regra pura summarize():
 * - state(): inactive (status inativo), scheduled (ainda não começou), ended (já acabou) ou showing (no ar hoje);
 * - rows: uma linha por alcance de SCOPES (deste condomínio e de todos os condomínios), com banners e uma contagem por
 *   situação de STATES, que somam banners; totals soma as linhas;
 * - showing_now: banners no ar hoje para o condomínio (os dele e os de todos), igual a totals.showing;
 * - ids (até MAX_IDS cada, com truncated): showing_ids (pela ordem de exibição: posição e início), ending_soon_ids (no ar e
 *   acabando em até SOON_DAYS dias, do que acaba antes) e ended_active_ids (acabados ainda ativos, para inativar).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * situações, alcances, contagens e ids: nenhum título, texto nem quem cadastrou.
 */
final class BannerOverview
{
    public const MAX_ROWS = 20000;
    public const MAX_IDS = 200;
    public const SOON_DAYS = 7;
    /** Situação de um banner no dia. */
    public const STATES = ['showing' => 'No ar', 'scheduled' => 'Agendado', 'ended' => 'Encerrado', 'inactive' => 'Inativo'];
    /** Alcance: deste condomínio ou de todos (sem condomínio). */
    public const SCOPES = ['condominium' => 'Deste condomínio', 'global' => 'Todos os condomínios'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação (regra pura) de um banner no dia. */
    public static function state(string $status, string $startsOn, string $endsOn, string $today): string
    {
        return match (true) {
            $status !== 'active' => 'inactive',
            $startsOn > $today => 'scheduled',
            $endsOn < $today => 'ended',
            default => 'showing',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, global:bool, status:string, starts_on:string, ends_on:string, position:?int}> $banners
     */
    public static function summarize(array $banners, string $today): array
    {
        $zero = ['banners' => 0] + array_fill_keys(array_keys(self::STATES), 0);
        $counts = array_fill_keys(array_keys(self::SCOPES), $zero);
        $soonLimit = (new \DateTimeImmutable($today))->modify(sprintf('+%d days', self::SOON_DAYS))->format('Y-m-d');
        $showing = [];
        $soon = [];
        $endedActive = [];
        foreach ($banners as $b) {
            $scope = $b['global'] ? 'global' : 'condominium';
            $state = self::state($b['status'], $b['starts_on'], $b['ends_on'], $today);
            $counts[$scope]['banners']++;
            $counts[$scope][$state]++;
            if ($state === 'showing') {
                $showing[] = [$b['position'] ?? PHP_INT_MAX, $b['starts_on'], $b['id']];
                if ($b['ends_on'] <= $soonLimit) {
                    $soon[] = [$b['ends_on'], $b['id']];
                }
            } elseif ($state === 'ended') {
                $endedActive[] = $b['id'];
            }
        }
        $rows = [];
        $totals = $zero;
        foreach (self::SCOPES as $scope => $label) {
            $rows[] = ['scope' => $scope, 'scope_label' => $label] + $counts[$scope];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$scope][$key];
            }
        }
        sort($showing);
        sort($soon);
        sort($endedActive);
        $cut = static fn (array $ids): array => ['ids' => array_slice($ids, 0, self::MAX_IDS), 'truncated' => count($ids) > self::MAX_IDS];
        $showingIds = $cut(array_column($showing, 2));
        $soonIds = $cut(array_column($soon, 1));
        $ended = $cut($endedActive);
        return ['on' => $today, 'rows' => $rows, 'totals' => $totals, 'showing_now' => $totals['showing'],
            'showing_ids' => $showingIds['ids'], 'showing_truncated' => $showingIds['truncated'],
            'ending_soon_ids' => $soonIds['ids'], 'ending_soon_truncated' => $soonIds['truncated'],
            'ended_active_ids' => $ended['ids'], 'ended_active_truncated' => $ended['truncated']];
    }

    public function build(User $user, mixed $condominiumId, ?string $today = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $table = (string)RegistryService::definition('banners')['table'];
        // Os do condomínio e os de todos (sem condomínio), como no cadastro de escopo opcional.
        $stmt = $this->pdo->prepare("SELECT id, condominium_id, status, starts_on, ends_on, position FROM {$table}
            WHERE deleted_at IS NULL AND (condominium_id = ? OR condominium_id IS NULL) ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($raw) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d banners: fale com o suporte.', self::MAX_ROWS));
        }
        // O dia do banco, para comparar com as datas gravadas.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'],
            'global' => $r['condominium_id'] === null, 'status' => (string)$r['status'], 'starts_on' => substr((string)$r['starts_on'], 0, 10),
            'ends_on' => substr((string)$r['ends_on'], 0, 10), 'position' => $r['position'] === null ? null : (int)$r['position']], $raw), $today);
    }
}
