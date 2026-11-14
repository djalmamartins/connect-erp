<?php
/**
 * Segurança da conta: verificação em duas etapas opcional para o morador (ADR 0304).
 * @var array{enabled:bool, configured:bool, required:bool, recovery_remaining:int, setup?:array{secret:string, uri:string, qr:string}} $twoFactor
 */
$this->layout('default');
$tf = $twoFactor;
$codeField = '<label class="app-field">Código do aplicativo autenticador <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required placeholder="000000"></label>';
?>
<header class="app-page-head"><?php if (!empty($link)): ?><span class="app-eyebrow"><?= $this->e($link["condominium_name"]) ?></span><?php endif; ?><h1>Segurança da conta</h1><p>Você entra com e-mail e senha. Se quiser mais proteção, ligue a verificação em duas etapas.</p></header>
<div class="app-card">
    <div class="app-section__head">
        <h2>Verificação em duas etapas</h2>
        <span class="app-badge app-badge--<?= $tf['enabled'] ? 'success' : 'neutral' ?>"><?= $tf['enabled'] ? 'Ligada' : 'Desligada' ?></span>
    </div>
<?php if ($tf['enabled']): ?>
    <p>Ao entrar, além da senha, o aplicativo pede o código de 6 números do seu aplicativo autenticador. Restam <?= (int)$tf['recovery_remaining'] ?> códigos de recuperação.</p>
    <?php if ($tf['required']): ?>
        <p class="app-hint">Na sua conta a verificação é obrigatória, porque ela também tem acesso da equipe.</p>
    <?php else: ?>
        <form method="post" action="<?= app_url('/seguranca') ?>" class="app-form" data-app-confirm="Desligar a verificação em duas etapas?">
            <?= csrf_input() ?><input type="hidden" name="action" value="disable">
            <?= $codeField ?>
            <button class="app-button app-button--ghost" type="submit">Desligar</button>
        </form>
    <?php endif; ?>
    <details class="app-form-card">
        <summary>Gerar novos códigos de recuperação</summary>
        <form method="post" action="<?= app_url('/seguranca') ?>" class="app-form">
            <?= csrf_input() ?><input type="hidden" name="action" value="recovery">
            <?= $codeField ?>
            <button class="app-button app-button--ghost" type="submit">Gerar novos códigos</button>
        </form>
    </details>
<?php elseif (!$tf['configured'] || !isset($tf['setup'])): ?>
    <p class="app-muted">A verificação em duas etapas ainda não está disponível. Fale com a administração do condomínio.</p>
<?php else: ?>
    <p>Não é obrigatória. Se quiser ligar:</p>
    <ol class="app-steps">
        <li>Instale um aplicativo autenticador no celular (Google Authenticator, Microsoft Authenticator ou outro).</li>
        <li>Leia o QR code abaixo com ele, ou digite a chave.</li>
        <li>Escreva aqui o código de 6 números que aparecer.</li>
    </ol>
    <div class="app-qr"><?= $tf['setup']['qr'] ?></div>
    <p class="app-hint">Chave: <code><?= $this->e(trim(chunk_split($tf['setup']['secret'], 4, ' '))) ?></code></p>
    <form method="post" action="<?= app_url('/seguranca') ?>" class="app-form">
        <?= csrf_input() ?><input type="hidden" name="action" value="confirm">
        <?= $codeField ?>
        <button class="app-button" type="submit">Ligar a verificação</button>
    </form>
<?php endif; ?>
</div>
