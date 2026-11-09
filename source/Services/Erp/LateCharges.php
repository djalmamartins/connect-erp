<?php

declare(strict_types=1);

namespace Source\Services\Erp;

/**
 * Encargos por atraso e quebra de acordo (ADR 0040), regras puras.
 *
 * Encargos de uma conta vencida, em $asOf:
 *  - correção: variação acumulada do índice nos meses do vencimento até o mês anterior a $asOf
 *    (o índice do mês corrente ainda não saiu). Mês sem índice entra como zero e aparece em
 *    missing_index.
 *  - multa: fine_bp sobre o valor corrigido (2% = 200 pontos-base).
 *  - juros: monthly_interest_bp ao mês, pro rata die (30 dias), sobre o valor corrigido.
 * Valores em centavos, arredondados para o centavo mais próximo. Sem atraso, tudo zero.
 */
final class LateCharges
{
    public const DEFAULT_RULE = ['fine_bp' => 200, 'monthly_interest_bp' => 100, 'price_index' => 'IPCA', 'breach_days_late' => 30, 'breach_open_installments' => 2];

    /**
     * @param array{fine_bp:int, monthly_interest_bp:int, price_index:string} $rule
     * @param array<string, float|string> $rates competência AAAA-MM => variação do mês em %
     * @return array{days_late:int, correction_cents:int, fine_cents:int, interest_cents:int, charges_cents:int, total_cents:int, missing_index:list<string>}
     */
    public static function compute(int $principalCents, string $dueOn, string $asOf, array $rule, array $rates = []): array
    {
        $days = Agreements::daysLate($dueOn, $asOf);
        $zero = ['days_late' => $days, 'correction_cents' => 0, 'fine_cents' => 0, 'interest_cents' => 0, 'charges_cents' => 0,
            'total_cents' => $principalCents, 'missing_index' => []];
        if ($days === 0 || $principalCents <= 0) {
            return $zero;
        }
        $factor = 1.0;
        $missing = [];
        if (strtoupper((string)$rule['price_index']) !== 'NONE') {
            foreach (self::indexMonths($dueOn, $asOf) as $month) {
                if (!isset($rates[$month])) {
                    $missing[] = $month;
                    continue;
                }
                $factor *= 1 + ((float)$rates[$month]) / 100;
            }
        }
        $correction = (int)round($principalCents * $factor) - $principalCents;
        $base = $principalCents + $correction;
        $fine = (int)round($base * ((int)$rule['fine_bp']) / 10000);
        $interest = (int)round($base * ((int)$rule['monthly_interest_bp']) / 10000 * $days / 30);
        $charges = $correction + $fine + $interest;
        return ['days_late' => $days, 'correction_cents' => $correction, 'fine_cents' => $fine, 'interest_cents' => $interest,
            'charges_cents' => $charges, 'total_cents' => $principalCents + $charges, 'missing_index' => $missing];
    }

    /**
     * Meses de índice que corrigem uma conta: do mês do vencimento até o mês anterior a $asOf.
     *
     * @return list<string>
     */
    public static function indexMonths(string $dueOn, string $asOf): array
    {
        $month = new \DateTimeImmutable(substr($dueOn, 0, 7) . '-01');
        $stop = new \DateTimeImmutable(substr($asOf, 0, 7) . '-01');
        $months = [];
        while ($month < $stop && count($months) < 600) {
            $months[] = $month->format('Y-m');
            $month = $month->modify('+1 month');
        }
        return $months;
    }

    /**
     * Situação de quebra de um acordo (regra pura). Só olha parcelas válidas e em aberto: quebra se
     * alguma passou de breach_days_late dias de atraso ou se há breach_open_installments vencidas.
     *
     * @param list<array{number:int, due_on:string, paid_on:?string, status:string}> $parcels
     * @param array{breach_days_late:int, breach_open_installments:int} $rule
     * @return array{due:bool, overdue_installments:int, max_days_late:int, reasons:list<string>}
     */
    public static function breach(array $parcels, array $rule, string $asOf): array
    {
        $overdue = 0;
        $maxDays = 0;
        foreach ($parcels as $parcel) {
            if ($parcel['status'] !== 'active' || $parcel['paid_on'] !== null) {
                continue;
            }
            $days = Agreements::daysLate($parcel['due_on'], $asOf);
            if ($days > 0) {
                $overdue++;
                $maxDays = max($maxDays, $days);
            }
        }
        $reasons = [];
        if ($maxDays > (int)$rule['breach_days_late']) {
            $reasons[] = sprintf('Parcela com %d dias de atraso (limite %d).', $maxDays, (int)$rule['breach_days_late']);
        }
        if ((int)$rule['breach_open_installments'] > 0 && $overdue >= (int)$rule['breach_open_installments']) {
            $reasons[] = sprintf('%d parcelas vencidas em aberto (limite %d).', $overdue, (int)$rule['breach_open_installments']);
        }
        return ['due' => $reasons !== [], 'overdue_installments' => $overdue, 'max_days_late' => $maxDays, 'reasons' => $reasons];
    }
}
