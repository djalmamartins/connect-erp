<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;

/**
 * Quais e-mails o morador recebe (ADR 0601, pedido do Djalma em 20/11/2026). Só estes, mais nada:
 *  1. boleto no dia do vencimento (charge.due_today);
 *  2. prestação de contas do mês, que sai entre os dias 5 e 10 (accountability.published);
 *  3. aviso de atraso com 10, 15, 30, 45 e 60 dias (charge.overdue);
 *  4. convite de cadastro (resident.invitation);
 *  5. recuperação de senha, quando ele pede (fora da outbox: Auth::forget).
 * Os outros avisos para morador (boleto emitido, fatura, acordo, reserva, lista de espera, comunicado, aviso de
 * cobrança manual) não viram e-mail: entram na fila já cancelados e, se algum antigo estiver pendente, a fila cancela.
 * Regras puras, sem banco.
 */
final class ResidentEmailPolicy
{
    /** Eventos da outbox que viram e-mail para o morador. */
    public const ALLOWED = ['charge.due_today', 'charge.overdue', 'accountability.published', 'resident.invitation'];

    /** Dias de atraso em que o morador recebe aviso. */
    public const MILESTONES = [10, 15, 30, 45, 60];

    /** Se o cron falhar alguns dias, o aviso do marco ainda sai até este número de dias depois dele. */
    public const CATCH_UP_DAYS = 3;

    /** Prestação de contas: não sai antes deste dia do mês. */
    public const ACCOUNTABILITY_FROM_DAY = 5;
    public const ACCOUNTABILITY_TO_DAY = 10;

    public const DISABLED_REASON = 'E-mail desligado para o morador (ADR 0601): ele só recebe boleto no vencimento, atraso com 10/15/30/45/60 dias, prestação de contas, convite e senha.';

    public static function allows(string $eventType): bool
    {
        return in_array($eventType, self::ALLOWED, true);
    }

    /**
     * Marco de atraso a avisar hoje (regra pura): o maior marco já alcançado, desde que tenha sido alcançado há no
     * máximo CATCH_UP_DAYS dias e antes do marco seguinte. Fora disso, null (nada a avisar hoje).
     * Ex.: 10 dias → 10; 12 → 10; 14 → null; 15 → 15; 31 → 30; 63 → 60; 64 → null.
     */
    public static function overdueMilestone(int $daysLate): ?int
    {
        $found = null;
        foreach (self::MILESTONES as $index => $milestone) {
            if ($daysLate < $milestone) {
                break;
            }
            $next = self::MILESTONES[$index + 1] ?? PHP_INT_MAX;
            if ($daysLate <= $milestone + self::CATCH_UP_DAYS && $daysLate < $next) {
                $found = $milestone;
            }
        }
        return $found;
    }

    /**
     * Quando o e-mail da prestação de contas pode sair (regra pura): antes do dia 5, fica agendado para o dia 5 às 8h;
     * do dia 5 em diante, sai na hora (depois do dia 10 sai atrasado, para o morador não ficar sem).
     * Devolve AAAA-MM-DD HH:MM:SS ou null (já).
     */
    public static function accountabilitySendAt(DateTimeImmutable $now): ?string
    {
        if ((int)$now->format('j') >= self::ACCOUNTABILITY_FROM_DAY) {
            return null;
        }
        return $now->format('Y-m-') . sprintf('%02d', self::ACCOUNTABILITY_FROM_DAY) . ' 08:00:00';
    }
}
