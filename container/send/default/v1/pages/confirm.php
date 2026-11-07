<?php
/** Confirmação de cadastro. Variáveis: $first_name, $confirm_link. */
$this->layout('default', ['subject' => 'Confirme seu cadastro', 'preheader' => 'Falta só um passo para ativar sua conta.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">Seu cadastro foi criado. Para ativar a conta, confirme o seu e-mail:</p>
<p style="margin:0 0 24px;">
    <a href="<?= $this->e($confirm_link) ?>" style="display:inline-block;background:#0f5fa8;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">Confirmar e-mail</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6878;">Se o botão não funcionar, copie este endereço no navegador:</p>
<p style="margin:0 0 16px;font-size:13px;word-break:break-all;"><?= $this->e($confirm_link) ?></p>
<p style="margin:0;font-size:13px;color:#5b6878;">Se você não fez este cadastro, ignore esta mensagem.</p>
