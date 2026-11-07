<?php
/** Recuperação de senha. Variáveis: $first_name, $forget_link. */
$this->layout('default', ['subject' => 'Recuperação de senha', 'preheader' => 'Use o link para criar uma nova senha.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">Recebemos um pedido para redefinir a senha da sua conta. Para criar uma nova senha, use o botão abaixo:</p>
<p style="margin:0 0 24px;">
    <a href="<?= $this->e($forget_link) ?>" style="display:inline-block;background:#0f5fa8;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">Criar nova senha</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6878;">Se o botão não funcionar, copie este endereço no navegador:</p>
<p style="margin:0 0 16px;font-size:13px;word-break:break-all;"><?= $this->e($forget_link) ?></p>
<p style="margin:0;font-size:13px;color:#5b6878;">Se você não pediu a troca de senha, ignore esta mensagem. Sua senha atual continua valendo.</p>
