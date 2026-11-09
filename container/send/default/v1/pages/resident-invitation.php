<?php
/** Convite de morador (ADR 0090). Variáveis: $first_name, $condominium, $invitation_link, $expires_days. */
$this->layout('default', ['subject' => 'Convite para o portal do condomínio', 'preheader' => 'Crie seu acesso ao portal do condomínio.']);
?>
<p style="margin:0 0 16px;">Olá, <?= $this->e($first_name) ?>.</p>
<p style="margin:0 0 16px;">A administração do <?= $this->e($condominium) ?> convidou você para criar seu acesso ao portal do condomínio. Para aceitar, use o botão abaixo:</p>
<p style="margin:0 0 24px;">
    <a href="<?= $this->e($invitation_link) ?>" style="display:inline-block;background:#0f5fa8;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:bold;">Aceitar o convite</a>
</p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6878;">Se o botão não funcionar, copie este endereço no navegador:</p>
<p style="margin:0 0 16px;font-size:13px;word-break:break-all;"><?= $this->e($invitation_link) ?></p>
<p style="margin:0;font-size:13px;color:#5b6878;">O convite vale por <?= $this->e($expires_days) ?> dias e só pode ser usado uma vez. Se você não esperava este convite, ignore esta mensagem.</p>
