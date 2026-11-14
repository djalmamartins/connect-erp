<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;

/**
 * Situação das importações do condomínio (ADR 0284), somente leitura. As importações (ADR 0060 e seguintes: extrato OFX,
 * lançamentos, contas a pagar, frações, inadimplentes, leituras e NF-e) passam por uma prévia e, depois, são aplicadas. A
 * lista mostra uma por vez; ninguém vê, de uma vez, quantas prévias ficaram paradas sem aplicar (o arquivo foi conferido
 * e esquecido), há quanto tempo, quantas linhas tiveram erro e quando cada tipo foi aplicado pela última vez.
 *
 * Reusa o que já existe: os tipos e os rótulos de Imports::KINDS e as situações da importação (prévia e aplicada, a enum
 * status de erp_imports, como a Imports::apply usa). O tempo da prévia usa o relógio do banco (created_at e NOW() no
 * mesmo fuso).
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo de Imports::KINDS, sempre todos, com imports, previewed, applied e discarded (que somam
 *   imports; descartada desde a ADR 0285), rows
 *   (linhas lidas), errors (linhas com erro), with_errors (importações com algum erro) e last_applied_on; totals soma
 *   as linhas (last_applied_on é a mais recente);
 * - pending_by_age: as prévias ainda não aplicadas por dias desde o envio, em AGES (até 7, de 8 a 30, mais de 30),
 *   somando totals.previewed;
 * - pending_ids (da prévia mais antiga para a mais nova) e with_errors_ids, até MAX_IDS cada, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, contagens, datas e ids: nenhum nome de arquivo, caminho, hash, conteúdo do resumo nem quem enviou ou aplicou.
 */
final class ImportsOverview
{
    public const MAX_ROWS = 100000;
    public const MAX_IDS = 200;
    /** Situações de uma importação (a enum status de erp_imports; descartada desde a ADR 0285). */
    public const STATUSES = ['previewed' => 'Prévia', 'applied' => 'Aplicada', 'discarded' => 'Descartada'];
    /** Idade das prévias não aplicadas, em dias desde o envio: faixa => até quantos dias (null = sem limite). */
    public const AGES = ['ate_7' => 7, 'de_8_a_30' => 30, 'mais_de_30' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de idade (regra pura) para quantos dias se passaram desde o envio da prévia. */
    public static function age(int $days): string
    {
        foreach (self::AGES as $age => $limit) {
            if ($limit === null || $days <= $limit) {
                return $age;
            }
        }
        return (string)array_key_last(self::AGES);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, kind:string, status:string, rows_count:int, error_count:int, created_at:string, applied_at:?string}> $imports
     */
    public static function summarize(array $imports, \DateTimeImmutable $now): array
    {
        $zero = ['imports' => 0] + array_fill_keys(array_keys(self::STATUSES), 0) + ['rows' => 0, 'errors' => 0, 'with_errors' => 0];
        $counts = array_fill_keys(array_keys(Imports::KINDS), $zero);
        $last = array_fill_keys(array_keys(Imports::KINDS), null);
        $ages = array_fill_keys(array_keys(self::AGES), 0);
        $pending = [];
        $withErrors = [];
        $unknown = 0;
        $today = $now->setTime(0, 0);
        foreach ($imports as $i) {
            if (!isset($counts[$i['kind']]) || !isset(self::STATUSES[$i['status']])) {
                $unknown++;
                continue;
            }
            $row = &$counts[$i['kind']];
            $row['imports']++;
            $row[$i['status']]++;
            $row['rows'] += max(0, $i['rows_count']);
            $row['errors'] += max(0, $i['error_count']);
            if ($i['error_count'] > 0) {
                $row['with_errors']++;
                $withErrors[] = $i['id'];
            }
            unset($row);
            if ($i['status'] === 'previewed') {
                $created = new \DateTimeImmutable($i['created_at']);
                $ages[self::age((int)$created->setTime(0, 0)->diff($today)->days)]++;
                $pending[] = [$i['created_at'], $i['id']];
            } elseif ($i['applied_at'] !== null) {
                $on = substr($i['applied_at'], 0, 10);
                $last[$i['kind']] = $last[$i['kind']] === null || $on > $last[$i['kind']] ? $on : $last[$i['kind']];
            }
        }
        $rows = [];
        $totals = $zero + ['last_applied_on' => null];
        foreach (Imports::KINDS as $kind => $definition) {
            $rows[] = ['kind' => $kind, 'kind_label' => (string)$definition['label']] + $counts[$kind] + ['last_applied_on' => $last[$kind]];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$kind][$key];
            }
            if ($last[$kind] !== null && ($totals['last_applied_on'] === null || $last[$kind] > $totals['last_applied_on'])) {
                $totals['last_applied_on'] = $last[$kind];
            }
        }
        sort($pending);
        $pendingIds = array_column($pending, 1);
        sort($withErrors);
        return ['on' => $now->format('Y-m-d H:i:s'), 'rows' => $rows, 'totals' => $totals, 'unknown' => $unknown, 'pending_by_age' => $ages,
            'pending_ids' => array_slice($pendingIds, 0, self::MAX_IDS), 'pending_truncated' => count($pendingIds) > self::MAX_IDS,
            'with_errors_ids' => array_slice($withErrors, 0, self::MAX_IDS), 'with_errors_truncated' => count($withErrors) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, ?\DateTimeImmutable $now = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, kind, status, rows_count, error_count, created_at, applied_at FROM erp_imports WHERE condominium_id = ? ORDER BY id LIMIT '
            . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($raw) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d importações: fale com o suporte.', self::MAX_ROWS));
        }
        // O relógio do banco: created_at foi gravado por ele.
        $now ??= new \DateTimeImmutable((string)$this->pdo->query('SELECT NOW()')->fetchColumn());
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'],
            'status' => (string)$r['status'], 'rows_count' => (int)$r['rows_count'], 'error_count' => (int)$r['error_count'], 'created_at' => (string)$r['created_at'],
            'applied_at' => $r['applied_at'] === null ? null : (string)$r['applied_at']], $raw), $now);
    }
}
