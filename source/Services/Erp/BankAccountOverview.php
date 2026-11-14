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
 * Situação das contas bancárias do condomínio (ADR 0298), somente leitura. O cadastro de contas bancárias (CN-003) guarda
 * o tipo (corrente, poupança ou aplicação), a conta principal, a conta corrente de cada aplicação (ADR 0170) e a
 * situação; os lançamentos pagos apontam a conta (ADR 0024) e as transferências ligam duas contas (ADR 0057). O saldo das
 * contas (BankBalances) mostra o dinheiro, mas ninguém vê, por tipo, quantas contas estão ativas, se o condomínio tem uma
 * conta principal (e só uma), quais contas ficaram paradas no período e quais aplicações perderam a conta corrente.
 *
 * Reusa o que já existe: os cadastros do registro (RegistryService::definition: bank_accounts para a tabela, a permissão e
 * os tipos; entries para a tabela e a permissão), as regras do saldo (lançamento válido = status active, não excluído,
 * com "Pago em"; transferência válida = status active e do condomínio da conta, como em BankBalances::transferSql), a
 * regra da conta principal (uma ativa por condomínio) e a da aplicação (ligada a uma conta corrente ativa do mesmo
 * condomínio, ADR 0170). O dia é o do banco.
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo do cadastro, sempre todos, com accounts (ativas), inactive, main (ativas marcadas como
 *   principal), idle (ativas sem lançamento pago nem transferência no período), orphan (aplicações ativas sem conta
 *   corrente ativa do condomínio), paid_entries (lançamentos pagos no período nas ativas) e transfer_legs (pontas de
 *   transferência no período nas ativas: a transferência entre duas contas conta uma vez em cada); totals soma as linhas;
 * - main_issue: 'none' sem conta principal ativa, 'multiple' com mais de uma, null com exatamente uma;
 * - elsewhere: lançamentos pagos no período sem conta ou numa conta inativa ou removida, à parte; all_paid_entries soma
 *   as linhas e o elsewhere;
 * - idle_ids e orphan_ids: até MAX_IDS, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, contagens, datas e ids de contas: nenhum apelido, banco, agência, número de conta, carteira, saldo nem valor.
 */
final class BankAccountOverview
{
    public const MAX_ROWS = 5000;
    public const MAX_IDS = 200;
    public const DEFAULT_DAYS = 90;
    public const MAX_DAYS = 3650;

    private const COUNTERS = ['accounts', 'inactive', 'main', 'idle', 'orphan', 'paid_entries', 'transfer_legs'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array<string, string> os tipos de conta do cadastro */
    public static function kinds(): array
    {
        return (array)RegistryService::definition('bank_accounts')['fields']['kind']['options'];
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, kind:string, status:string, is_main:bool, orphan:bool}> $accounts
     * @param array<int|string, int> $entries lançamentos pagos no período por conta ('' = sem conta)
     * @param array<int, int> $transfers pontas de transferência no período por conta
     */
    public static function summarize(array $accounts, array $entries, array $transfers): array
    {
        $kinds = self::kinds();
        $counts = array_fill_keys(array_keys($kinds), array_fill_keys(self::COUNTERS, 0));
        $active = [];
        $idle = [];
        $orphans = [];
        foreach ($accounts as $a) {
            if (!isset($counts[$a['kind']])) {
                continue;
            }
            if ($a['status'] !== 'active') {
                $counts[$a['kind']]['inactive']++;
                continue;
            }
            $counts[$a['kind']]['accounts']++;
            $active[$a['id']] = true;
            if ($a['is_main']) {
                $counts[$a['kind']]['main']++;
            }
            $paid = $entries[$a['id']] ?? 0;
            $legs = $transfers[$a['id']] ?? 0;
            $counts[$a['kind']]['paid_entries'] += $paid;
            $counts[$a['kind']]['transfer_legs'] += $legs;
            if ($paid === 0 && $legs === 0) {
                $counts[$a['kind']]['idle']++;
                $idle[] = $a['id'];
            }
            if ($a['orphan']) {
                $counts[$a['kind']]['orphan']++;
                $orphans[] = $a['id'];
            }
        }
        $elsewhere = ['paid_entries' => 0];
        foreach ($entries as $id => $count) {
            if (!isset($active[$id])) {
                $elsewhere['paid_entries'] += $count;
            }
        }
        $rows = [];
        $totals = array_fill_keys(self::COUNTERS, 0);
        foreach ($kinds as $kind => $label) {
            $rows[] = ['kind' => $kind, 'kind_label' => $label] + $counts[$kind];
            foreach (self::COUNTERS as $key) {
                $totals[$key] += $counts[$kind][$key];
            }
        }
        sort($idle);
        sort($orphans);
        return ['rows' => $rows, 'totals' => $totals,
            'main_issue' => $totals['main'] === 0 ? 'none' : ($totals['main'] > 1 ? 'multiple' : null),
            'elsewhere' => $elsewhere, 'all_paid_entries' => $totals['paid_entries'] + $elsewhere['paid_entries'],
            'idle_ids' => array_slice($idle, 0, self::MAX_IDS), 'idle_truncated' => count($idle) > self::MAX_IDS,
            'orphan_ids' => array_slice($orphans, 0, self::MAX_IDS), 'orphan_truncated' => count($orphans) > self::MAX_IDS];
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
        $accounts = (string)RegistryService::definition('bank_accounts')['table'];
        $entries = (string)RegistryService::definition('entries')['table'];

        // Aplicação órfã (ADR 0170): sem conta corrente ativa, não excluída, do mesmo condomínio.
        $stmt = $this->pdo->prepare("SELECT b.id, b.kind, b.status, b.is_main,
                CASE WHEN b.kind = 'aplicacao' AND (p.id IS NULL OR p.deleted_at IS NOT NULL OR p.status <> 'active' OR p.kind <> 'corrente'
                    OR p.condominium_id <> b.condominium_id) THEN 1 ELSE 0 END AS orphan
            FROM {$accounts} b LEFT JOIN {$accounts} p ON p.id = b.parent_bank_account_id
            WHERE b.condominium_id = ? AND b.deleted_at IS NULL ORDER BY b.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d contas bancárias: fale com o suporte.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare("SELECT bank_account_id, COUNT(*) AS n FROM {$entries} WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND paid_on BETWEEN ? AND ? GROUP BY bank_account_id");
        $stmt->execute([(int)$id, $from, $today]);
        $paid = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $paid[$r['bank_account_id'] === null ? '' : (int)$r['bank_account_id']] = (int)$r['n'];
        }
        // Transferência válida, como no saldo (BankBalances::transferSql): ativa e do condomínio; cada ponta conta na sua conta.
        $legs = [];
        foreach (['from_bank_account_id', 'to_bank_account_id'] as $column) {
            $stmt = $this->pdo->prepare("SELECT {$column} AS account_id, COUNT(*) AS n FROM erp_bank_transfers WHERE condominium_id = ? AND status = 'active'
                AND transferred_on BETWEEN ? AND ? GROUP BY {$column}");
            $stmt->execute([(int)$id, $from, $today]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $legs[(int)$r['account_id']] = ($legs[(int)$r['account_id']] ?? 0) + (int)$r['n'];
            }
        }
        return ['condominium_id' => (int)$id, 'days' => (int)$window, 'from' => $from, 'to' => $today] + self::summarize(
            array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'],
                'is_main' => (bool)$r['is_main'], 'orphan' => (bool)$r['orphan']], $rows),
            $paid,
            $legs
        );
    }
}
