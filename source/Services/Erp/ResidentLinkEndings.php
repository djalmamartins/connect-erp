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
 * Vínculos perto do fim ou vencidos, por faixa de prazo (ADR 0242), somente leitura. O cadastro de proprietários e
 * inquilinos guarda o fim do vínculo (ends_on); o perfil de ocupação (ADR 0240) desconta os vencidos e o encerramento em
 * lote (ADR 0241) os acerta, mas ninguém vê antes quantos contratos de locação terminam nos próximos meses nem quantos já
 * venceram sem baixa. O painel operacional e o calendário de obrigações cobrem seguros, contratos de fornecedor e mandatos,
 * não os vínculos das unidades. A administradora usa isso para antecipar renovações e mudanças e para conferir o que o
 * encerramento em lote vai pegar.
 *
 * Reusa o que já existe: os tipos de vínculo do cadastro (RegistryService::definition: residents), a regra das listas (só
 * ativos, sem excluídos) e a mesma regra de vencido do 0241 (fim anterior à data on; o próprio dia ainda vale).
 *
 * Regra pura bucket() e summarize(), por vínculo ativo com fim:
 * - expired_over_90, expired_31_90, expired_1_30: o fim passou há tantos dias;
 * - ending_0_30, ending_31_60, ending_61_90: o fim chega em tantos dias (0 = termina na data on).
 * Uma linha por faixa, sempre as seis, com links, by_relationship (os tipos do cadastro, somando links) e inactive_unit
 * (os que apontam para unidade inativa ou excluída); totals soma as linhas. Fora das linhas: open_ended (sem fim) e later
 * (fim depois de 90 dias). closable = vencidos com unidade ativa ou sem unidade: o que o 0241 encerraria com before = on.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * faixas, os tipos e as contagens: nenhum nome, documento, contato, unidade, data individual ou observação.
 */
final class ResidentLinkEndings
{
    public const MAX_ROWS = 100000;
    public const BUCKETS = [
        'expired_over_90' => 'Vencido há mais de 90 dias',
        'expired_31_90' => 'Vencido há 31 a 90 dias',
        'expired_1_30' => 'Vencido há até 30 dias',
        'ending_0_30' => 'Termina em até 30 dias',
        'ending_31_60' => 'Termina em 31 a 60 dias',
        'ending_61_90' => 'Termina em 61 a 90 dias',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa do vínculo pelo fim (regra pura): null = sem fim; 'later' = depois de 90 dias. */
    public static function bucket(?string $endsOn, string $on): ?string
    {
        $ends = substr((string)$endsOn, 0, 10);
        if ($ends === '') {
            return null;
        }
        $days = (int)(new DateTimeImmutable($on))->diff(new DateTimeImmutable($ends))->format('%r%a');
        return match (true) {
            $days < -90 => 'expired_over_90',
            $days < -30 => 'expired_31_90',
            $days < 0 => 'expired_1_30',
            $days <= 30 => 'ending_0_30',
            $days <= 60 => 'ending_31_60',
            $days <= 90 => 'ending_61_90',
            default => 'later',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{relationship:string, ends_on:?string, unit_active:bool}> $links vínculos ativos
     */
    public static function summarize(array $links, string $on): array
    {
        $relationships = array_keys(RegistryService::definition('residents')['fields']['relationship']['options']);
        $rows = [];
        foreach (self::BUCKETS as $bucket => $label) {
            $rows[$bucket] = ['bucket' => $bucket, 'bucket_label' => $label, 'links' => 0, 'by_relationship' => array_fill_keys($relationships, 0), 'inactive_unit' => 0];
        }
        $openEnded = 0;
        $later = 0;
        foreach ($links as $link) {
            if (!in_array($link['relationship'], $relationships, true)) {
                continue;
            }
            $bucket = self::bucket($link['ends_on'], $on);
            if ($bucket === null) {
                $openEnded++;
                continue;
            }
            if ($bucket === 'later') {
                $later++;
                continue;
            }
            $rows[$bucket]['links']++;
            $rows[$bucket]['by_relationship'][$link['relationship']]++;
            if (!$link['unit_active']) {
                $rows[$bucket]['inactive_unit']++;
            }
        }
        $totals = ['links' => 0, 'by_relationship' => array_fill_keys($relationships, 0), 'inactive_unit' => 0];
        $closable = 0;
        foreach ($rows as $bucket => $row) {
            $totals['links'] += $row['links'];
            $totals['inactive_unit'] += $row['inactive_unit'];
            foreach ($relationships as $relationship) {
                $totals['by_relationship'][$relationship] += $row['by_relationship'][$relationship];
            }
            if (str_starts_with($bucket, 'expired_')) {
                $closable += $row['links'] - $row['inactive_unit'];
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals, 'closable' => $closable, 'open_ended' => $openEnded, 'later' => $later];
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

        $stmt = $this->pdo->prepare("SELECT id FROM erp_units WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT "
            . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $units = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $this->pdo->prepare("SELECT unit_id, relationship, ends_on FROM erp_residents WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($units) > self::MAX_ROWS || count($links) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d registros: fale com o suporte.', self::MAX_ROWS));
        }
        $active = array_fill_keys(array_map('intval', $units), true);
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize(
            array_map(static fn (array $l): array => ['relationship' => (string)$l['relationship'],
                'ends_on' => $l['ends_on'] !== null ? substr((string)$l['ends_on'], 0, 10) : null,
                'unit_active' => $l['unit_id'] === null || isset($active[(int)$l['unit_id']])], $links),
            $on);
    }
}
