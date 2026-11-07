<?php $this->layout('layouts/login'); ?>
<main class="connect-login connect-login--compact">
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span>VERIFICAÇÃO EM DUAS ETAPAS</span>
                <?php if ($mode === 'setup'): ?>
                    <h2>Configure o seu autenticador</h2>
                    <p>A verificação em duas etapas é obrigatória. Adicione a conta no Google Authenticator, Microsoft Authenticator ou similar e informe o código gerado.</p>
                <?php else: ?>
                    <h2>Informe o código</h2>
                    <p>Digite o código de 6 dígitos do seu aplicativo autenticador ou um código de recuperação.</p>
                <?php endif; ?>
            </header>
            <div class="ajax_response"><?= flash() ?></div>
            <?php if ($mode === 'setup' && $setup): ?>
                <div class="connect-login__setup">
                    <p><strong>Chave de configuração</strong></p>
                    <p><code class="connect-login__secret"><?= htmlspecialchars(trim(chunk_split($setup['secret'], 4, ' '))) ?></code></p>
                    <p><a href="<?= htmlspecialchars($setup['uri']) ?>">Abrir no aplicativo autenticador</a></p>
                </div>
            <?php endif; ?>
            <form action="<?= url('/studio/login/2fa') ?>" method="post" autocomplete="off">
                <?= csrf_input() ?>
                <input type="hidden" name="next" value="<?= htmlspecialchars(ltrim($next, '/')) ?>">
                <label><span>Código</span><span class="connect-login__field"><input name="code" inputmode="<?= $mode === 'setup' ? 'numeric' : 'text' ?>" autocomplete="one-time-code" maxlength="16" placeholder="000000" required autofocus></span></label>
                <button class="connect-login__submit" type="submit"><span>Verificar</span><strong>→</strong></button>
            </form>
            <footer><a href="<?= url($next . '/login') ?>">Voltar e entrar com outra conta</a></footer>
        </div>
    </section>
</main>
