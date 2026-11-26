<?php
/**
 * Tela de entrada única (ERP, Studio, Help Desk e app do morador na web, ADR 0657).
 * No app ($loginEnvironment 'app') o painel da foto fala com o morador e o cartão mostra o aviso
 * de conta sem unidade ($loginNotice) e a nota do convite.
 */
$this->layout('layouts/login');
$isResidentApp = ($loginEnvironment ?? 'studio') === 'app';
?>
<main class="connect-login" style="--login-cover:url('<?= theme('/assets/images/bkg_home_connect.jpg') ?>')">
    <section class="connect-login__visual">
        <div class="connect-login__shade"></div>
        <div class="connect-login__visual-content">
            <a class="connect-login__brand" href="<?= url('/') ?>"><img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios"></a>
            <div class="connect-login__pitch">
                <span class="connect-login__line"></span>
                <?php if ($isResidentApp): ?>
                <h1>O seu condomínio<br><strong>na palma da mão</strong></h1>
                <p>Boletos, reservas, encomendas, visitantes e avisos do seu prédio.</p>
                <div class="connect-login__benefits">
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 4 40 10v12c0 10-6.5 17-16 22C14.5 39 8 32 8 22V10z"/><path d="m17 24 5 5 10-11"/></svg></span><h2>Conta protegida</h2><p>Use uma senha só sua e ative a verificação em duas etapas no Perfil.</p></article>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M6 12h36v24H6z"/><path d="m7 13 17 14 17-14"/></svg></span><h2>Desconfie de links</h2><p>Entre sempre por este endereço ou pelo aplicativo, nunca por links de mensagens estranhas.</p></article>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="16" cy="30" r="8"/><path d="m22 24 16-16M32 14l5 5M28 18l4 4"/></svg></span><h2>Primeiro acesso</h2><p>A conta nasce pelo convite que a administração envia por e-mail.</p></article>
                </div>
                <?php else: ?>
                <h1>Gestão inteligente para<br><strong>condomínios de excelência</strong></h1>
                <p>Tecnologia, segurança e transparência para simplificar a rotina e valorizar o que importa.</p>
                <div class="connect-login__benefits">
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 4 40 10v12c0 10-6.5 17-16 22C14.5 39 8 32 8 22V10z"/><path d="m17 24 5 5 10-11"/></svg></span><h2>Segurança</h2><p>Proteção de dados e informações com alto padrão.</p></article>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M8 39V27h7v12M20 39V19h7v20M32 39V10h7v29M8 20l9-8 8 4L39 5"/><path d="M33 5h6v6"/></svg></span><h2>Eficiência</h2><p>Processos organizados para uma gestão produtiva.</p></article>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="16" r="8"/><path d="M9 42c1-10 6-15 15-15s14 5 15 15"/></svg></span><h2>Transparência</h2><p>Informações claras e acessíveis em tempo real.</p></article>
                </div>
                <?php endif; ?>
            </div>
            <blockquote><?= $isResidentApp ? 'A Connect nunca pede a sua senha por telefone, e-mail ou WhatsApp.' : 'Mais que um sistema, uma parceria que conecta pessoas e valoriza patrimônios.' ?></blockquote>
        </div>
    </section>
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <?php [$loginEyebrow, $loginTitle, $loginLead] = match ($loginEnvironment ?? 'studio') {
                    'helpdesk' => ['SAC CONNECT', 'SAC', 'Acesse para atender e acompanhar chamados'],
                    'erp' => ['ERP CONNECT CONDOMÍNIOS', 'Bem-vindo(a) de volta!', 'Acesse a gestão dos condomínios da Connect'],
                    'app' => ['CONNECT MORADOR', 'Área do morador', 'Entre para ver os boletos, as reservas e os avisos do seu condomínio'],
                    default => ['MOVESOS', 'Bem-vindo(a) de volta!', 'Acesse sua conta para continuar'],
                }; ?>
                <span><?= $loginEyebrow ?></span>
                <h2><?= $loginTitle ?></h2>
                <p><?= $loginLead ?></p>
            </header>
            <div class="ajax_response" role="status" aria-live="polite"><?php if (!empty($loginNotice)): ?><div class="message info icon-info"><?= htmlspecialchars($loginNotice) ?></div><?php endif; ?><?= flash() ?></div>
            <form action="<?= url(($adminBase ?? '/studio').'/login') ?>" method="post">
                <?= csrf_input() ?>
                <label><span>E-mail</span><span class="connect-login__field"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6.5h18v12H3zM3.5 7l8.5 7 8.5-7"/></svg><input name="email" type="email" value="<?= htmlspecialchars($cookie ?? '') ?>" placeholder="seu@email.com" autocomplete="username" required></span></label>
                <label><span>Senha</span><span class="connect-login__field"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10h12v10H6zM8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/></svg><input id="studioPassword" name="password" type="password" placeholder="Sua senha" autocomplete="current-password" required><button class="connect-login__eye" type="button" aria-label="Mostrar senha" aria-controls="studioPassword"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5z"/><circle cx="12" cy="12" r="2.5"/></svg></button></span></label>
                <div class="connect-login__options"><label class="connect-login__remember"><input type="checkbox" name="save" value="1"<?= !empty($cookie) ? ' checked' : '' ?>><span>Lembrar meu e-mail</span></label><?php if (!empty($forgotUrl)): ?><a href="<?= htmlspecialchars($forgotUrl) ?>">Esqueci minha senha</a><?php else: ?><span>Área restrita</span><?php endif; ?></div>
                <button class="connect-login__submit" type="submit"><span>Entrar</span><strong>→</strong></button>
            </form>
            <?php if ($isResidentApp): ?><p class="connect-login__note">Primeiro acesso? Use o link do convite que a administração enviou para o seu e-mail para criar a senha.</p><?php endif; ?>
            <footer><span class="connect-login__lock" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span> Ambiente seguro e monitorado</footer>
        </div>
        <a class="connect-login__back" href="<?= url('/') ?>">← Voltar ao site</a>
    </section>
</main>
<?php $this->start('scripts') ?><script>(function(){const b=document.querySelector('.connect-login__eye'),p=document.getElementById('studioPassword');if(!b||!p)return;b.addEventListener('click',function(){const v=p.type==='text';p.type=v?'password':'text';b.setAttribute('aria-label',v?'Mostrar senha':'Ocultar senha');b.classList.toggle('is-visible',!v);});})();</script><?php $this->stop() ?>
