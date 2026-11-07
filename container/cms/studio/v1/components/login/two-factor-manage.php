<?php $this->layout('layouts/login'); ?>
<main class="connect-login connect-login--compact">
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span>SEGURANÇA DA CONTA</span>
                <h2>Verificação em duas etapas</h2>
                <p><?= $enabled ? 'Ativa. Seu login exige o código do aplicativo autenticador.' : 'Desativada. Ative para proteger o acesso ao Studio e ao ERP.' ?></p>
            </header>
            <div class="ajax_response"><?= flash() ?></div>

            <?php if (!empty($recoveryCodes)): ?>
                <div class="connect-login__setup" role="alert">
                    <p><strong>Guarde estes códigos de recuperação agora.</strong> Cada um funciona uma única vez e eles não serão exibidos novamente.</p>
                    <ul class="connect-login__codes"><?php foreach ($recoveryCodes as $code): ?><li><code><?= htmlspecialchars((string)$code) ?></code></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if (!$configured && !$enabled): ?>
                <p>A verificação em duas etapas ainda não está configurada no servidor (CONNECT_2FA_KEY). Avise o administrador.</p>
            <?php elseif (!$enabled && $setup): ?>
                <div class="connect-login__setup">
                    <p>1. Adicione a conta no seu aplicativo autenticador com a chave:</p>
                    <p><code class="connect-login__secret"><?= htmlspecialchars(trim(chunk_split($setup['secret'], 4, ' '))) ?></code></p>
                    <p><a href="<?= htmlspecialchars($setup['uri']) ?>">Abrir no aplicativo autenticador</a></p>
                    <p>2. Informe o código gerado para ativar.</p>
                </div>
                <form action="<?= url('/studio/seguranca/2fa') ?>" method="post" autocomplete="off">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="confirm">
                    <label><span>Código</span><span class="connect-login__field"><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required></span></label>
                    <button class="connect-login__submit" type="submit"><span>Ativar</span><strong>→</strong></button>
                </form>
            <?php elseif ($enabled): ?>
                <p>Códigos de recuperação disponíveis: <strong><?= (int)$remaining ?></strong></p>
                <form action="<?= url('/studio/seguranca/2fa') ?>" method="post" autocomplete="off">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="recovery">
                    <label><span>Código atual do autenticador</span><span class="connect-login__field"><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required></span></label>
                    <button class="connect-login__submit" type="submit"><span>Gerar novos códigos de recuperação</span></button>
                </form>
                <?php if (!$enforced): ?>
                <form action="<?= url('/studio/seguranca/2fa') ?>" method="post" autocomplete="off">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="disable">
                    <label><span>Código atual do autenticador</span><span class="connect-login__field"><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required></span></label>
                    <button class="connect-login__submit connect-login__submit--secondary" type="submit"><span>Desativar</span></button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            <footer><a href="<?= url('/studio/dash') ?>">Voltar ao Studio</a></footer>
        </div>
    </section>
</main>
