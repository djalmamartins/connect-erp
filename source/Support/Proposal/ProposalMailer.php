<?php

namespace Source\Support\Proposal;

use Source\Models\Proposal\Proposal;
use Source\Models\User;
use Source\Support\MailTemplate;

/** E-mails do módulo de propostas na moldura Connect (container send, ADR 0397). */
final class ProposalMailer
{
    /** Confirmação para quem pediu a proposta pelo site. */
    public static function site(Proposal $proposal): string
    {
        return (new MailTemplate())->render('notice', [
            'subject' => 'Recebemos sua solicitação',
            'preheader' => 'Recebemos o pedido de proposta para o seu condomínio.',
            'first_name' => (string)$proposal->name,
            'paragraphs' => ['Obrigado pelo interesse. Nossa equipe analisará as informações do seu condomínio e entrará em contato para preparar uma proposta personalizada.'],
            'highlight' => [['Protocolo', (string)$proposal->protocol]],
            'note' => 'Dúvidas? Fale conosco pelo telefone ' . (defined('CONF_SITE_PHONE') ? CONF_SITE_PHONE : '') . ' ou responda este e-mail.',
            'footer_note' => 'Você recebe este e-mail porque pediu uma proposta no site. Você pode responder a esta mensagem.',
        ]);
    }

    /** Aviso para a equipe de que chegou uma proposta. */
    public static function system(Proposal $proposal, User $recipient): string
    {
        return (new MailTemplate())->render('notice', [
            'subject' => 'Nova proposta recebida',
            'preheader' => "{$proposal->name} pediu uma proposta para {$proposal->condominium}.",
            'first_name' => (string)($recipient->first_name ?: $recipient->fullName()),
            'paragraphs' => ["{$proposal->name} solicitou uma proposta para {$proposal->condominium}, com " . (int)$proposal->units . ' unidades.'],
            'highlight' => [['Protocolo', (string)$proposal->protocol]],
            'button_label' => 'Abrir a proposta',
            'button_link' => ProposalService::erpLink((int)$proposal->id),
        ]);
    }

    /** Envio da proposta pronta (o PDF vai anexado). */
    public static function response(Proposal $proposal): string
    {
        return (new MailTemplate())->render('notice', [
            'subject' => 'Sua proposta está pronta',
            'preheader' => 'A proposta para o seu condomínio está anexada.',
            'first_name' => (string)$proposal->name,
            'paragraphs' => [
                "Preparamos a proposta solicitada para {$proposal->condominium}. O documento completo está anexado a este e-mail.",
                'Caso queira ajustar algum ponto, responda este e-mail e nossa equipe dará continuidade ao atendimento.',
            ],
            'highlight' => [['Protocolo', (string)$proposal->protocol]],
            'footer_note' => 'Você recebe este e-mail porque pediu uma proposta no site. Você pode responder a esta mensagem.',
        ]);
    }
}
