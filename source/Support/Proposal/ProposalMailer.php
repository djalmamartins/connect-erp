<?php

namespace Source\Support\Proposal;

use Source\Models\Proposal\Proposal;
use Source\Models\User;

final class ProposalMailer
{
    public static function site(Proposal $proposal): string
    {
        $name = self::e((string)$proposal->name);
        $protocol = self::e((string)$proposal->protocol);
        $site = self::e(CONF_SITE_NAME);
        $address = self::e(self::address());
        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f3f3ef;font-family:Arial,sans-serif;color:#173d33"><table role="presentation" width="100%"><tr><td style="padding:32px 15px"><table role="presentation" width="100%" style="max-width:640px;margin:auto;overflow:hidden;background:#fff;border:1px solid #e7e4da;border-radius:10px"><tr><td style="padding:28px 34px;background:#063d32;border-bottom:4px solid #d1a331"><strong style="font-size:22px;color:#d9ad3c">'.$site.'</strong><br><small style="color:#dce8e4">Gestão condominial que conecta</small></td></tr><tr><td style="padding:36px 34px"><p style="margin-top:0">Olá, '.$name.'.</p><h1 style="margin:0 0 18px;font-size:25px;color:#063d32">Recebemos sua solicitação</h1><p style="font-size:15px;line-height:1.7;color:#59645f">Obrigado pelo interesse. Nossa equipe analisará as informações do seu condomínio e entrará em contato para preparar uma proposta personalizada.</p><div style="margin:24px 0;padding:16px;border-left:4px solid #d1a331;background:#faf8f0"><small style="color:#7a7463">PROTOCOLO</small><br><strong style="font-size:18px;color:#063d32">'.$protocol.'</strong></div><p style="font-size:13px;line-height:1.6;color:#7a817e">Dúvidas? Fale conosco pelo telefone '.self::e(CONF_SITE_PHONE).' ou responda este e-mail.</p></td></tr><tr><td style="padding:20px 34px;background:#f7f6f1;color:#777;font-size:12px">'.$address.'</td></tr></table></td></tr></table></body></html>';
    }

    public static function system(Proposal $proposal, User $recipient): string
    {
        $name = self::e($recipient->first_name ?: $recipient->fullName());
        $link = self::e(url('/studio/proposals/' . $proposal->id));
        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f5f3f7;font-family:Arial,sans-serif;color:#29232e"><table role="presentation" width="100%"><tr><td style="padding:32px 15px"><table role="presentation" width="100%" style="max-width:640px;margin:auto;background:#fff;border:1px solid #e9e2ed;border-radius:9px"><tr><td style="padding:25px 32px;border-bottom:4px solid #6E00B3"><strong style="font-size:21px;color:#6E00B3">MovesOS</strong><br><small style="color:#777">Módulo de Propostas</small></td></tr><tr><td style="padding:34px 32px"><p style="margin-top:0">Olá, '.$name.'.</p><h1 style="font-size:23px">Nova proposta recebida</h1><p style="line-height:1.65;color:#5f5865"><strong>'.self::e($proposal->name).'</strong> solicitou uma proposta para <strong>'.self::e($proposal->condominium).'</strong>, com '.(int)$proposal->units.' unidades.</p><p style="margin:28px 0"><a href="'.$link.'" style="display:inline-block;padding:13px 21px;border-radius:6px;background:#6E00B3;color:#fff;text-decoration:none;font-weight:700">Abrir no MovesOS</a></p><small style="color:#89818d">Protocolo '.self::e($proposal->protocol).'</small></td></tr></table></td></tr></table></body></html>';
    }

    public static function response(Proposal $proposal): string
    {
        $link = self::e(url('/solicite-sua-proposta'));
        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f3f3ef;font-family:Arial,sans-serif;color:#173d33"><table role="presentation" width="100%"><tr><td style="padding:32px 15px"><table role="presentation" width="100%" style="max-width:640px;margin:auto;background:#fff;border:1px solid #e7e4da;border-radius:10px"><tr><td style="padding:28px 34px;background:#063d32;border-bottom:4px solid #d1a331"><strong style="font-size:22px;color:#d9ad3c">'.self::e(CONF_SITE_NAME).'</strong><br><small style="color:#dce8e4">Proposta personalizada</small></td></tr><tr><td style="padding:36px 34px"><p style="margin-top:0">Olá, '.self::e($proposal->name).'.</p><h1 style="font-size:24px;color:#063d32">Sua proposta está pronta</h1><p style="font-size:15px;line-height:1.7;color:#59645f">Preparamos a proposta solicitada para <strong>'.self::e($proposal->condominium).'</strong>. O documento completo está anexado a este e-mail.</p><p style="font-size:14px;line-height:1.7;color:#59645f">Caso queira ajustar algum ponto, responda este e-mail e nossa equipe dará continuidade ao atendimento.</p><div style="margin-top:25px;padding:14px;border-left:4px solid #d1a331;background:#faf8f0"><small>PROTOCOLO</small><br><strong>'.self::e($proposal->protocol).'</strong></div></td></tr><tr><td style="padding:20px 34px;background:#f7f6f1;color:#777;font-size:12px">'.self::e(self::address()).' · <a href="'.$link.'" style="color:#063d32">'.self::e(CONF_SITE_NAME).'</a></td></tr></table></td></tr></table></body></html>';
    }

    private static function address(): string
    {
        return trim(CONF_SITE_ADDR_STREET . ', ' . CONF_SITE_ADDR_NUMBER . (CONF_SITE_ADDR_COMPLEMENT ? ' - ' . CONF_SITE_ADDR_COMPLEMENT : '') . ' - ' . CONF_SITE_ADDR_DISTRICT . ', ' . CONF_SITE_ADDR_CITY . ' - ' . CONF_SITE_ADDR_STATE . ', CEP ' . CONF_SITE_ADDR_ZIPCODE, ' ,-');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
