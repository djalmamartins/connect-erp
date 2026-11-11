<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Acompanhamento dos acordos (ADR 0207), somente leitura: quais acordos ativos já atendem à regra de quebra do
 * condomínio (BillingRules, ADR 0056) e quais estão atrasados e chegando lá, para a administradora agir antes (ligar,
 * renegociar) e para o síndico ver a carteira de acordos de uma vez, sem abrir acordo por acordo.
 *
 * - Cada acordo ativo do escopo é classificado pela regra pura classify(), com o mesmo cálculo da quebra
 *   (LateCharges::breach, a mesma do breachStatus do acordo):
 *   - breach_due: atende à regra de quebra (dias de atraso ou parcelas vencidas acima do limite), com os motivos;
 *   - late: tem parcela vencida em aberto, ainda abaixo da regra, com days_to_breach (dias até passar do limite de
 *     atraso) e installments_to_breach (parcelas vencidas que faltam para o limite, quando a regra usa parcelas);
 *   - on_track: nada vencido.
 * - Nada muda: marcar a quebra continua sendo a ação explícita do acordo (Agreements::breach, com motivo e o aviso à
 *   unidade). Este relatório não grava e não envia nada.
 * - Totais por situação (quantidade, em aberto e vencido) e itens de breach_due e late, mais graves primeiro (até
 *   MAX_ITEMS, com truncated). Acordo quebrado ou cancelado fica de fora (já resolvido).
 *
 * Escopo (ADR-002): um condomínio (fora = CondominiumAccessDenied, 404) ou todos os do escopo. Saem condomínio, unidade
 * (rótulo), número do acordo, valores e datas: nenhum nome, documento, e-mail ou telefone de morador.
 */
final class AgreementWatch
{
    public const MAX_AGREEMENTS = 2000;
    public const MAX_ITEMS = 500;
    public const STATES = ['breach_due', 'late', 'on_track'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Situação de um acordo em $asOf (regra pura).
     *
     * @param list<array{number:int, due_on:string, paid_on:?string, status:string, amount_cents:int}> $parcels
     * @param array{breach_days_late:int, breach_open_installments:int} $rule
     */
    public static function classify(array $parcels, array $rule, string $asOf): array
    {
        $breach = LateCharges::breach($parcels, $rule, $asOf);
        $open = 0;
        $overdueCents = 0;
        $next = null;
        foreach ($parcels as $parcel) {
            if ($parcel['status'] !== 'active' || $parcel['paid_on'] !== null) {
                continue;
            }
            $open += $parcel['amount_cents'];
            if (Agreements::daysLate($parcel['due_on'], $asOf) > 0) {
                $overdueCents += $parcel['amount_cents'];
            } elseif ($next === null || $parcel['due_on'] < $next) {
                $next = $parcel['due_on'];
            }
        }
        $state = $breach['due'] ? 'breach_due' : ($breach['overdue_installments'] > 0 ? 'late' : 'on_track');
        $limit = (int)$rule['breach_open_installments'];
        return ['state' => $state, 'overdue_installments' => $breach['overdue_installments'], 'overdue_cents' => $overdueCents, 'open_cents' => $open,
            'max_days_late' => $breach['max_days_late'], 'next_due_on' => $next, 'reasons' => $breach['reasons'],
            'days_to_breach' => $state === 'late' ? (int)$rule['breach_days_late'] - $breach['max_days_late'] + 1 : null,
            'installments_to_breach' => $state === 'late' && $limit > 0 ? $limit - $breach['overdue_installments'] : null];
    }

    public function build(User $user, mixed $condominiumId, mixed $asOf = null): array
    {
        $asOf = $asOf === null || $asOf === '' ? date('Y-m-d') : $asOf;
        $date = is_string($asOf) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf) : false;
        if ($date === false || $date->format('Y-m-d') !== $asOf) {
            throw new InvalidArgumentException('Data de referência: AAAA-MM-DD.');
        }
        if ($condominiumId === null || $condominiumId === '') {
            $ids = $this->scope->allowedIds($user);
        } else {
            $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new InvalidArgumentException('Condomínio inválido.');
            }
            $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar
            $ids = [(int)$id];
        }
        $empty = ['agreements' => 0, 'open_cents' => 0, 'overdue_cents' => 0];
        $result = ['as_of' => $asOf, 'condominium_id' => count($ids) === 1 && $condominiumId !== null && $condominiumId !== '' ? $ids[0] : null,
            'summary' => array_fill_keys(self::STATES, $empty), 'items' => [], 'truncated' => false];
        if ($ids === []) {
            return $result;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT a.id, a.condominium_id, c.name AS condominium_name, a.unit_id, u.label AS unit_label, a.installments, a.total_cents,
                DATE(a.created_at) AS created_on
            FROM erp_agreements a JOIN condominiums c ON c.id = a.condominium_id JOIN erp_units u ON u.id = a.unit_id
            WHERE a.status = 'active' AND a.condominium_id IN ({$marks}) ORDER BY c.name, a.id LIMIT " . (self::MAX_AGREEMENTS + 1));
        $stmt->execute($ids);
        $agreements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($agreements) > self::MAX_AGREEMENTS) {
            throw new InvalidArgumentException(sprintf('Mais de %d acordos ativos: escolha um condomínio.', self::MAX_AGREEMENTS));
        }
        if ($agreements === []) {
            return $result;
        }
        $agreementIds = array_map(static fn (array $a): int => (int)$a['id'], $agreements);
        $parcelsBy = [];
        foreach (array_chunk($agreementIds, 500) as $chunk) {
            $stmt = $this->pdo->prepare("SELECT agreement_id, agreement_installment, due_on, paid_on, status, amount_cents FROM erp_entries
                WHERE agreement_role = 'installment' AND deleted_at IS NULL AND agreement_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                ORDER BY agreement_id, agreement_installment');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $parcelsBy[(int)$p['agreement_id']][] = ['number' => (int)$p['agreement_installment'], 'due_on' => substr((string)$p['due_on'], 0, 10),
                    'paid_on' => $p['paid_on'], 'status' => (string)$p['status'], 'amount_cents' => (int)$p['amount_cents']];
            }
        }
        $rules = new BillingRules($this->pdo, $this->scope);
        $ruleBy = [];
        $items = [];
        foreach ($agreements as $a) {
            $condo = (int)$a['condominium_id'];
            $ruleBy[$condo] ??= $rules->rule($condo);
            $status = self::classify($parcelsBy[(int)$a['id']] ?? [], $ruleBy[$condo], $asOf);
            $sum = &$result['summary'][$status['state']];
            $sum['agreements']++;
            $sum['open_cents'] += $status['open_cents'];
            $sum['overdue_cents'] += $status['overdue_cents'];
            unset($sum);
            if ($status['state'] !== 'on_track') {
                $items[] = ['agreement_id' => (int)$a['id'], 'condominium_id' => $condo, 'condominium_name' => (string)$a['condominium_name'],
                    'unit_id' => (int)$a['unit_id'], 'unit_label' => (string)$a['unit_label'], 'created_on' => (string)$a['created_on'],
                    'installments' => (int)$a['installments'], 'total_cents' => (int)$a['total_cents'],
                    'rule' => ['breach_days_late' => (int)$ruleBy[$condo]['breach_days_late'], 'breach_open_installments' => (int)$ruleBy[$condo]['breach_open_installments']]] + $status;
            }
        }
        usort($items, static fn (array $x, array $y): int => [$x['state'] === 'breach_due' ? 0 : 1, -$x['max_days_late'], -$x['overdue_cents'], $x['agreement_id']]
            <=> [$y['state'] === 'breach_due' ? 0 : 1, -$y['max_days_late'], -$y['overdue_cents'], $y['agreement_id']]);
        $result['truncated'] = count($items) > self::MAX_ITEMS;
        $result['items'] = array_slice($items, 0, self::MAX_ITEMS);
        return $result;
    }
}
