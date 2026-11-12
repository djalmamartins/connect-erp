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
 * Carteira de contratos do condomínio numa data (ADR 0246), somente leitura. O cadastro de contratos (AP-119) guarda a
 * vigência, o valor mensal, o índice de reajuste e o contrato assinado; o painel operacional conta os vencendo e vencidos,
 * o calendário de obrigações lista os fins, a renovação (ADR 0217) estende e o encerramento (ADR 0245) fecha. Ninguém vê,
 * porém, a carteira: quanto o condomínio tem contratado por mês, por índice de reajuste e por prazo, quanto é de prazo
 * indeterminado, quantos contratos estão sem o documento assinado e quais reajustes caem nos próximos 90 dias. A
 * administradora usa isso na previsão orçamentária e na assembleia.
 *
 * Reusa o que já existe: os índices de reajuste do cadastro (RegistryService::definition: contracts), a regra das listas
 * (sem excluídos), a regra de anexo do cadastro (documento no Disco Virtual do mesmo condomínio, não removido) e a mesma
 * regra do encerramento em lote (ADR 0245) para closable: vigentes no cadastro com o fim anterior à data e com fornecedor
 * ativo e documento, se houver, no Disco Virtual.
 *
 * Regra pura bucket(), nextAnniversary() e summarize(), por contrato vigente no cadastro:
 * - expired: o fim é anterior à data on (o próprio dia vale);
 * - ending_0_30, ending_31_90, ending_over_90: em vigor, com o fim em tantos dias;
 * - open_ended: em vigor, de prazo indeterminado;
 * - not_started: o início é depois de on.
 * Uma linha por faixa, sempre as seis, com contracts, monthly_cents, by_readjustment (os índices do cadastro, somando
 * monthly_cents) e without_document; totals soma as linhas. Fora das linhas: closable; readjustment_due (contratos em
 * vigor com índice cujo aniversário do início cai de on até on + 90 dias, com a soma mensal); ended e cancelled.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * faixas, os índices, as contagens e as somas: nenhum objeto, fornecedor, documento ou observação.
 */
final class ContractPortfolio
{
    public const MAX_ROWS = 10000;
    public const BUCKETS = [
        'expired' => 'Vencido e ainda vigente no cadastro',
        'ending_0_30' => 'Em vigor, termina em até 30 dias',
        'ending_31_90' => 'Em vigor, termina em 31 a 90 dias',
        'ending_over_90' => 'Em vigor, termina depois de 90 dias',
        'open_ended' => 'Em vigor, prazo indeterminado',
        'not_started' => 'Começa depois da data',
    ];
    public const READJUSTMENT_WINDOW_DAYS = 90;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa do contrato pela vigência (regra pura). */
    public static function bucket(string $startsOn, ?string $endsOn, string $on): string
    {
        $starts = substr($startsOn, 0, 10);
        $ends = substr((string)$endsOn, 0, 10);
        if ($ends !== '' && $ends < $on) {
            return 'expired';
        }
        if ($starts > $on) {
            return 'not_started';
        }
        if ($ends === '') {
            return 'open_ended';
        }
        $days = (int)(new DateTimeImmutable($on))->diff(new DateTimeImmutable($ends))->days;
        return match (true) {
            $days <= 30 => 'ending_0_30',
            $days <= 90 => 'ending_31_90',
            default => 'ending_over_90',
        };
    }

    /**
     * Próximo aniversário do início a partir de on (regra pura): o mesmo dia e mês, a partir do ano seguinte ao início;
     * 29/02 vira 28/02 nos anos sem o dia.
     */
    public static function nextAnniversary(string $startsOn, string $on): string
    {
        $start = new DateTimeImmutable(substr($startsOn, 0, 10));
        $year = max((int)$start->format('Y') + 1, (int)substr($on, 0, 4));
        while (true) {
            $month = (int)$start->format('n');
            $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            $date = sprintf('%04d-%02d-%02d', $year, $month, min((int)$start->format('j'), $last));
            if ($date >= $on) {
                return $date;
            }
            $year++;
        }
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{starts_on:string, ends_on:?string, monthly_cents:int, readjustment:string, has_document:bool, links_active:bool}> $contracts vigentes no cadastro
     */
    public static function summarize(array $contracts, string $on, int $ended = 0, int $cancelled = 0): array
    {
        $indexes = array_keys(RegistryService::definition('contracts')['fields']['readjustment']['options']);
        $rows = [];
        foreach (self::BUCKETS as $bucket => $label) {
            $rows[$bucket] = ['bucket' => $bucket, 'bucket_label' => $label, 'contracts' => 0, 'monthly_cents' => 0,
                'by_readjustment' => array_fill_keys($indexes, 0), 'without_document' => 0];
        }
        $closable = 0;
        $due = ['contracts' => 0, 'monthly_cents' => 0];
        $window = (new DateTimeImmutable($on))->modify('+' . self::READJUSTMENT_WINDOW_DAYS . ' days')->format('Y-m-d');
        foreach ($contracts as $c) {
            $bucket = self::bucket($c['starts_on'], $c['ends_on'], $on);
            $index = in_array($c['readjustment'], $indexes, true) ? $c['readjustment'] : 'nenhum';
            $rows[$bucket]['contracts']++;
            $rows[$bucket]['monthly_cents'] += $c['monthly_cents'];
            $rows[$bucket]['by_readjustment'][$index] += $c['monthly_cents'];
            if (!$c['has_document']) {
                $rows[$bucket]['without_document']++;
            }
            if ($bucket === 'expired' && $c['links_active']) {
                $closable++;
            }
            if (!in_array($bucket, ['expired', 'not_started'], true) && $index !== 'nenhum') {
                $anniversary = self::nextAnniversary($c['starts_on'], $on);
                $ends = substr((string)$c['ends_on'], 0, 10);
                if ($anniversary <= $window && ($ends === '' || $anniversary <= $ends)) {
                    $due['contracts']++;
                    $due['monthly_cents'] += $c['monthly_cents'];
                }
            }
        }
        $totals = ['contracts' => 0, 'monthly_cents' => 0, 'by_readjustment' => array_fill_keys($indexes, 0), 'without_document' => 0];
        foreach ($rows as $row) {
            $totals['contracts'] += $row['contracts'];
            $totals['monthly_cents'] += $row['monthly_cents'];
            $totals['without_document'] += $row['without_document'];
            foreach ($indexes as $index) {
                $totals['by_readjustment'][$index] += $row['by_readjustment'][$index];
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals, 'closable' => $closable, 'readjustment_due' => $due, 'ended' => $ended, 'cancelled' => $cancelled];
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

        $stmt = $this->pdo->prepare("SELECT c.starts_on, c.ends_on, c.monthly_cents, c.readjustment, c.document_id, d.id AS doc, s.id AS supplier_ok
            FROM erp_contracts c
            LEFT JOIN erp_documents d ON d.id = c.document_id AND d.condominium_id = c.condominium_id AND d.deleted_at IS NULL
            LEFT JOIN erp_suppliers s ON s.id = c.supplier_id AND s.deleted_at IS NULL AND s.status = 'active'
            WHERE c.condominium_id = ? AND c.deleted_at IS NULL AND c.status = 'active' ORDER BY c.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d contratos: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT status, COUNT(*) FROM erp_contracts WHERE condominium_id = ? AND deleted_at IS NULL AND status IN ('ended', 'inactive')
            GROUP BY status");
        $stmt->execute([(int)$id]);
        $others = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        return ['condominium_id' => (int)$id, 'on' => $on] + self::summarize(
            array_map(static fn (array $r): array => ['starts_on' => substr((string)$r['starts_on'], 0, 10),
                'ends_on' => $r['ends_on'] !== null ? substr((string)$r['ends_on'], 0, 10) : null, 'monthly_cents' => (int)$r['monthly_cents'],
                'readjustment' => (string)$r['readjustment'], 'has_document' => $r['doc'] !== null,
                'links_active' => $r['supplier_ok'] !== null && ($r['document_id'] === null || $r['doc'] !== null)], $rows),
            $on, $others['ended'] ?? 0, $others['inactive'] ?? 0);
    }
}
