<?php
/**
 * Segunda etapa do login (ADR 0620): a mesma moldura da tela de login (foto, painel e cartão),
 * com dicas de segurança no lugar dos textos de apresentação e o código em 6 campos.
 * Serve ERP, Studio, Help Desk e app do morador na web ($next).
 */
$this->layout('layouts/login');
$isSetup = $mode === 'setup';
$loginBack = $next === '/app' ? app_url('/login') : url($next . '/login');
[$loginEyebrow, $loginLead] = match ($next) {
    '/erp' => ['ERP CONNECT CONDOMÍNIOS', 'Mais uma etapa para entrar na gestão dos condomínios'],
    '/helpdesk' => ['SAC CONNECT', 'Mais uma etapa para entrar no SAC'],
    '/app' => ['CONNECT MORADOR', 'Mais uma etapa para entrar no aplicativo do morador'],
    default => ['MOVESOS', 'Mais uma etapa para entrar na sua conta'],
};
$tips = $isSetup ? [
    ['phone', 'Use um autenticador', 'Google Authenticator, Microsoft Authenticator ou similar, instalado no seu celular.'],
    ['clock', 'Código novo a cada 30 segundos', 'O aplicativo troca o código sozinho. Use sempre o que está na tela.'],
    ['key', 'Guarde os códigos de recuperação', 'Eles aparecem depois desta etapa e servem se você trocar ou perder o celular.'],
] : [
    ['shield', 'Nunca compartilhe o código', 'A Connect nunca pede este código por telefone, e-mail ou WhatsApp.'],
    ['clock', 'Código novo a cada 30 segundos', 'Se o código mudar enquanto você digita, use o novo que aparecer.'],
    ['key', 'Sem o celular?', 'Use um dos códigos de recuperação que você guardou ao ativar a verificação.'],
];
$icons = [
    'shield' => '<path d="M24 4 40 10v12c0 10-6.5 17-16 22C14.5 39 8 32 8 22V10z"/><path d="m17 24 5 5 10-11"/>',
    'clock' => '<circle cx="24" cy="24" r="17"/><path d="M24 14v10l7 5"/>',
    'key' => '<circle cx="16" cy="30" r="8"/><path d="m22 24 16-16M32 14l5 5M28 18l4 4"/>',
    'phone' => '<rect x="14" y="5" width="20" height="38" rx="4"/><path d="M21 37h6"/>',
];
?>
<main class="connect-login connect-login--two-factor" style="--login-cover:url('<?= theme('/assets/images/bkg_home_connect.jpg') ?>')">
    <section class="connect-login__visual" aria-label="Dicas de segurança">
        <div class="connect-login__shade"></div>
        <div class="connect-login__visual-content">
            <a class="connect-login__brand" href="<?= url('/') ?>"><img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios"></a>
            <div class="connect-login__pitch">
                <span class="connect-login__line"></span>
                <h1>Sua conta protegida<br><strong>em duas etapas</strong></h1>
                <p>Além da senha, pedimos um código que só o seu celular gera. Assim, ninguém entra só com a sua senha.</p>
                <div class="connect-login__benefits">
                    <?php foreach ($tips as [$icon, $title, $text]): ?>
                        <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><?= $icons[$icon] ?></svg></span><h2><?= $title ?></h2><p><?= $text ?></p></article>
                    <?php endforeach; ?>
                </div>
            </div>
            <blockquote>Não foi você que tentou entrar agora? Não digite o código, troque a sua senha e avise a administração.</blockquote>
        </div>
    </section>
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span><?= $loginEyebrow ?></span>
                <h2><?= $isSetup ? 'Configure o autenticador' : 'Verificação em duas etapas' ?></h2>
                <p><?= $isSetup ? 'Obrigatória para todos. Leva menos de um minuto.' : $loginLead ?></p>
            </header>
            <div class="ajax_response" role="status" aria-live="polite"><?= flash() ?></div>
            <?php if ($isSetup && $setup): ?>
                <div class="connect-login__setup">
                    <?php if (!empty($setup['qr'])): ?>
                        <div class="connect-login__qr"><span><?= $setup['qr'] ?></span></div>
                    <?php endif; ?>
                    <p<?= empty($setup['qr']) ? ' class="connect-login__setup-row"' : '' ?>><strong>Escaneie o QR code</strong> no aplicativo autenticador. Sem câmera? Digite a chave abaixo.</p>
                    <p class="connect-login__setup-row"><code class="connect-login__secret"><?= htmlspecialchars(trim(chunk_split($setup['secret'], 4, ' '))) ?></code></p>
                    <p class="connect-login__setup-row"><a href="<?= htmlspecialchars($setup['uri']) ?>">Abrir no aplicativo autenticador</a></p>
                </div>
            <?php endif; ?>
            <form action="<?= url('/studio/login/2fa') ?>" method="post" autocomplete="off" data-otp-form>
                <?= csrf_input() ?>
                <input type="hidden" name="next" value="<?= htmlspecialchars(ltrim($next, '/')) ?>">
                <input type="hidden" name="code" value="" data-otp-value>
                <fieldset class="connect-login__otp" data-otp-panel>
                    <legend id="otpLegend">Código de 6 dígitos do aplicativo</legend>
                    <div class="connect-login__otp-boxes" role="group" aria-labelledby="otpLegend">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <input class="connect-login__otp-digit" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" data-otp-digit
                                   aria-label="Dígito <?= $i ?> de 6" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>"<?= $i === 1 ? ' autofocus' : '' ?>>
                        <?php endfor; ?>
                    </div>
                </fieldset>
                <?php if (!$isSetup): ?>
                    <label class="connect-login__recovery" data-recovery-panel hidden><span>Código de recuperação</span><span class="connect-login__field"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2.5 2.5M14 9l2 2"/></svg><input type="text" maxlength="16" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="xxxxx-xxxxx" data-recovery-input></span></label>
                <?php endif; ?>
                <div class="connect-login__options">
                    <?php if (!$isSetup): ?><button class="connect-login__link" type="button" data-otp-toggle aria-pressed="false">Usar código de recuperação</button><?php else: ?><span></span><?php endif; ?>
                    <a href="<?= $loginBack ?>">Entrar com outra conta</a>
                </div>
                <button class="connect-login__submit" type="submit"><span>Verificar</span><strong>→</strong></button>
            </form>
            <footer><span class="connect-login__lock" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span> Ambiente seguro e monitorado</footer>
        </div>
        <a class="connect-login__back" href="<?= url('/') ?>">← Voltar ao site</a>
    </section>
</main>
<?php $this->start('scripts') ?><script src="<?= themeStudio('/assets/js/login-otp.js', 'default') ?>"></script><?php $this->stop() ?>
