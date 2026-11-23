<?php
/**
 * Moldura única dos e-mails do sistema (ambiente send, A4; ADR 0397, visual branco e dourado no ADR 0646).
 * Cartão branco sem bordas nem linhas douradas, logo Connect dourado sobre branco, fonte Inter (com alternativas),
 * botão verde (#07884c), quadros e rodapé em verde bem claro (#f6faf8) e links em dourado escuro (#8a6d1c).
 * Sem o cabeçalho verde-escuro: o e-mail é leve e limpo.
 *
 * Variáveis: $subject, $preheader, $heading (título no corpo; padrão = assunto; '' esconde),
 * $site_name, $logo_url, $site_url, $footer_note (padrão: "não responda"), $privacy_url, $company_cnpj,
 * $company_address, $company_phone, $company_email (MailTemplate::defaults) e $unsubscribe_url/$unsubscribe_label
 * (link de descadastro/preferências; aparece quando o envio informa).
 * Conteúdo na seção 'content'. Estilos inline e tabelas, porque clientes de e-mail ignoram CSS externo.
 */

$heading = $heading ?? ($subject ?? '');
$siteName = (string)($site_name ?? 'Connect');
$siteUrl = (string)($site_url ?? '');
$siteHost = $siteUrl !== '' ? (string)preg_replace('~^https?://(www\.)?~i', '', rtrim($siteUrl, '/')) : '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= $this->e($subject ?? '') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#eef1ef;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#3a3730;-webkit-text-size-adjust:100%;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:#eef1ef;"><?= $this->e($preheader ?? '') ?></span>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef1ef;">
    <tr>
        <td align="center" style="padding:32px 12px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border-collapse:separate;">
                <tr>
                    <td align="center" style="padding:32px 28px 8px;background:#ffffff;">
                        <?php if (($logo_url ?? '') !== ''): ?>
                            <img src="<?= $this->e($logo_url) ?>" width="152" height="40" alt="<?= $this->e($siteName) ?>" style="display:block;margin:0 auto;border:0;width:152px;height:40px;color:#8a6d1c;font-size:18px;font-weight:700;">
                        <?php else: ?>
                            <span style="font-size:20px;font-weight:700;color:#8a6d1c;letter-spacing:.5px;"><?= $this->e($siteName) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 36px 28px;font-size:15px;line-height:1.65;color:#3a3730;">
                        <?php if ($heading !== ''): ?>
                            <h1 style="margin:0 0 20px;font-size:22px;line-height:1.3;font-weight:600;color:#1f1d17;"><?= $this->e($heading) ?></h1>
                        <?php endif; ?>
                        <?= $this->section('content') ?>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:22px 36px 26px;background:#f6faf8;font-size:12px;line-height:1.7;color:#6f6a5e;text-align:center;border-radius:0 0 12px 12px;">
                        <p style="margin:0 0 10px;"><?= $this->e($footer_note ?? "Mensagem automática de {$siteName}. Não responda a este e-mail.") ?></p>
                        <p style="margin:0 0 10px;">
                            <strong style="color:#3a3730;font-weight:600;"><?= $this->e($siteName) ?></strong><?php if (($company_cnpj ?? '') !== ''): ?> · CNPJ <?= $this->e($company_cnpj) ?><?php endif; ?>
                            <?php if (($company_address ?? '') !== ''): ?><br><?= $this->e($company_address) ?><?php endif; ?>
                            <?php if (($company_phone ?? '') !== '' || ($company_email ?? '') !== ''): ?><br><?= $this->e(implode(' · ', array_filter([(string)($company_phone ?? ''), (string)($company_email ?? '')]))) ?><?php endif; ?>
                        </p>
                        <p style="margin:0;">
                            <?php if ($siteHost !== ''): ?><a href="<?= $this->e($siteUrl) ?>" style="color:#8a6d1c;font-weight:600;text-decoration:underline;"><?= $this->e($siteHost) ?></a><?php endif; ?>
                            <?php if (($privacy_url ?? '') !== ''): ?> · <a href="<?= $this->e($privacy_url) ?>" style="color:#8a6d1c;text-decoration:underline;">Política de privacidade</a><?php endif; ?>
                            <?php if (($unsubscribe_url ?? '') !== ''): ?> · <a href="<?= $this->e($unsubscribe_url) ?>" style="color:#8a6d1c;text-decoration:underline;"><?= $this->e($unsubscribe_label ?? 'Descadastrar este e-mail') ?></a><?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
