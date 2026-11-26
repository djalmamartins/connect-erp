<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * O que vira aviso no sininho da equipe (ERP, Studio e Help Desk), pedido do Djalma em 22/11/2026 (ADR 0645):
 * "só o básico": o que foi enviado, chamado, ocorrência, proposta e pequenas coisas do trabalho do dia.
 *
 * FICA no sininho (cada um uma vez, para quem cuida):
 *  - chamado e ocorrência: novo chamado, resposta e mudança de situação (TicketService);
 *  - mensagem enviada pela Central de notificações do Studio (Communication);
 *  - proposta nova pedida no site (ProposalService);
 *  - compromisso novo na agenda do Help Desk;
 *  - ação de cobrança marcada para hoje ou atrasada (CollectionActionReminders);
 *  - prazo da prestação de contas (AccountabilityDeadline);
 *  - item do checklist de chegada vencendo ou vencido (OnboardingAlerts);
 *  - tarefas atrasadas no Meu Dia, uma vez por dia às 7h (MyDayAlerts, ADR 0658).
 *
 * SAI do sininho (o registro continua guardado, só não avisa):
 *  - "Erro detectado no MovesOS": o incidente fica em Studio › Logs do sistema, sem aviso nem e-mail;
 *  - "Alteração no sistema": a trilha fica em Studio › Auditoria, sem aviso a cada registro alterado;
 *  - "Novo artigo: ...": artigo do blog não avisa toda a equipe.
 */
final class StaffNotificationPolicy
{
    /** Títulos que não devem mais aparecer no sininho. */
    public const SILENCED_TITLES = ['Erro detectado no MovesOS', 'Alteração no sistema'];

    /** Prefixos de título que não devem mais aparecer no sininho. */
    public const SILENCED_PREFIXES = ['Novo artigo: '];

    public static function silenced(string $title): bool
    {
        if (in_array($title, self::SILENCED_TITLES, true)) {
            return true;
        }
        foreach (self::SILENCED_PREFIXES as $prefix) {
            if (str_starts_with($title, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
