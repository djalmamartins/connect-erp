<?php
/**
 * Moldura única dos e-mails transacionais (ambiente send, A4).
 * Variáveis: $subject, $preheader, $site_name. Conteúdo na seção 'content'.
 * Estilos inline e tabelas, porque clientes de e-mail ignoram CSS externo.
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $this->e($subject ?? '') ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1d2733;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= $this->e($preheader ?? '') ?></span>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fa;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #d9e0e8;border-radius:8px;">
                <tr>
                    <td style="padding:20px 24px;border-bottom:1px solid #d9e0e8;font-size:18px;font-weight:bold;"><?= $this->e($site_name ?? '') ?></td>
                </tr>
                <tr>
                    <td style="padding:24px;font-size:15px;line-height:1.6;">
                        <?= $this->section('content') ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid #d9e0e8;font-size:12px;line-height:1.5;color:#5b6878;">
                        Mensagem automática de <?= $this->e($site_name ?? '') ?>. Não responda a este e-mail.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
