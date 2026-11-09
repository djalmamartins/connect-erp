<?php
/**
 * Segurança da conta no ERP: verificação em duas etapas (TOTP) do próprio usuário.
 * @var bool $twoFactorEnabled @var bool $twoFactorConfigured @var bool $twoFactorEnforced
 * @var ?array{secret: string, uri: string} $twoFactorSetup
 * @var list<string> $recoveryCodes @var int $recoveryRemaining
 */
$this->layout('default');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Minha conta',
    'pageTitle' => 'Segurança da conta',
    'pageLead' => 'A verificação em duas etapas pede, no login, um código do aplicativo autenticador além da senha.',
]);
$codeField = static fn (string $id): string => '<div class="erp-field erp-col-6"><label for="' . $id . '">Código do aplicativo autenticador</label><input class="erp-input" id="' . $id . '" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" required><span class="erp-field__error" aria-live="polite"></span></div>';
?>
<?php if ($recoveryCodes !== []): ?>
    <section class="erp-card" aria-labelledby="sec-codes-title">
        <div class="erp-alert erp-alert--warning"><?= $icon('alert') ?><div><strong id="sec-codes-title">Guarde estes códigos de recuperação agora</strong>Cada um permite entrar uma única vez se você perder o aplicativo autenticador. Eles não serão mostrados de novo.</div></div>
        <ul class="erp-codes">
            <?php foreach ($recoveryCodes as $code): ?><li><?= $this->e($code) ?></li><?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<div class="erp-grid erp-grid--main">
    <section class="erp-card" aria-labelledby="sec-2fa-title">
        <div class="erp-card__head">
            <div><h2 id="sec-2fa-title">Verificação em duas etapas</h2><p><?= $twoFactorEnabled ? 'Seu login exige o código do aplicativo autenticador.' : 'Ative para proteger o acesso ao ERP.' ?></p></div>
            <span class="erp-badge erp-badge--<?= $twoFactorEnabled ? 'success' : 'warning' ?>"><?= $twoFactorEnabled ? 'Ativa' : 'Desativada' ?></span>
        </div>

        <?php if (!$twoFactorEnabled && !$twoFactorConfigured): ?>
            <div class="erp-alert erp-alert--danger"><?= $icon('alert') ?><div><strong>Não configurada no servidor</strong>A chave de criptografia do 2FA (CONNECT_2FA_KEY) não está definida. Avise o administrador.</div></div>
        <?php elseif (!$twoFactorEnabled && $twoFactorSetup): ?>
            <ol class="erp-steps-list">
                <li>No Google Authenticator, Microsoft Authenticator ou similar, <?= !empty($twoFactorSetup['qr']) ? 'escaneie o QR code ou adicione' : 'adicione' ?> uma conta com a chave abaixo (ou abra o link no celular).</li>
                <li>Digite o código de 6 dígitos que o aplicativo mostrar.</li>
            </ol>
            <?php if (!empty($twoFactorSetup['qr'])): ?><figure class="erp-qr"><?= $twoFactorSetup['qr'] ?><figcaption>Aponte a câmera do aplicativo autenticador</figcaption></figure><?php endif; ?>
            <p class="erp-secret"><code><?= $this->e(trim(chunk_split($twoFactorSetup['secret'], 4, ' '))) ?></code></p>
            <p><a href="<?= $this->e($twoFactorSetup['uri']) ?>">Abrir no aplicativo autenticador</a></p>
            <form class="erp-form" method="post" action="<?= erp_url('/seguranca') ?>" autocomplete="off" data-erp-validate data-erp-loading>
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="confirm">
                <div class="erp-form__grid"><?= $codeField('sec-confirm-code') ?></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('shield', 'erp-icon--sm') ?>Ativar</button></div>
            </form>
        <?php elseif ($twoFactorEnabled): ?>
            <p>Códigos de recuperação disponíveis: <strong><?= (int)$recoveryRemaining ?></strong></p>
            <form class="erp-form" method="post" action="<?= erp_url('/seguranca') ?>" autocomplete="off" data-erp-validate data-erp-loading>
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="recovery">
                <div class="erp-form__grid"><?= $codeField('sec-recovery-code') ?></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn">Gerar novos códigos de recuperação</button></div>
            </form>
            <?php if (!$twoFactorEnforced): ?>
                <form class="erp-form" method="post" action="<?= erp_url('/seguranca') ?>" autocomplete="off" data-erp-validate data-erp-loading style="margin-top:20px">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="disable">
                    <div class="erp-form__grid"><?= $codeField('sec-disable-code') ?></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--danger">Desativar a verificação em duas etapas</button></div>
                </form>
            <?php else: ?>
                <p class="erp-field__hint">A verificação em duas etapas é obrigatória para todos os usuários e não pode ser desativada.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <aside class="erp-card" aria-labelledby="sec-tips-title">
        <div class="erp-card__head"><div><h2 id="sec-tips-title">Boas práticas</h2></div></div>
        <ul class="erp-list">
            <li><span class="erp-list__icon"><?= $icon('lock', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Guarde os códigos de recuperação</strong><small>Fora do celular, em local seguro.</small></div></li>
            <li><span class="erp-list__icon"><?= $icon('users', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Não compartilhe o acesso</strong><small>Cada pessoa da equipe usa o próprio login.</small></div></li>
            <li><span class="erp-list__icon"><?= $icon('alert', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Perdeu o celular?</strong><small>Entre com um código de recuperação e gere novos.</small></div></li>
        </ul>
    </aside>
</div>
