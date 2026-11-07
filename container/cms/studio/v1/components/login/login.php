<?php $this->layout('layouts/login'); ?>
<main class="connect-login" style="--login-cover:url('<?= theme('/assets/images/bkg_home_connect.jpg') ?>')">
    <section class="connect-login__visual">
        <div class="connect-login__shade"></div>
        <div class="connect-login__visual-content">
            <a class="connect-login__brand" href="<?= url('/') ?>"><img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios"></a>
            <div class="connect-login__pitch">
                <span class="connect-login__line"></span>
                <h1>Gestão inteligente para<br><strong>condomínios de excelência</strong></h1>
                <p>Tecnologia, segurança e transparência para simplificar a rotina e valorizar o que importa.</p>
                <div class="connect-login__benefits">
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 4 40 10v12c0 10-6.5 17-16 22C14.5 39 8 32 8 22V10z"/><path d="m17 24 5 5 10-11"/></svg></span><h2>Segurança</h2><p>Proteção de dados e informações com alto padrão.</p></article>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M8 39V27h7v12M20 39V19h7v20M32 39V10h7v29M8 20l9-8 8 4L39 5"/><path d="M33 5h6v6"/></svg></span><h2>Eficiência</h2><p>Processos organizados para uma gestão produtiva.</p></article>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="16" r="8"/><path d="M9 42c1-10 6-15 15-15s14 5 15 15"/></svg></span><h2>Transparência</h2><p>Informações claras e acessíveis em tempo real.</p></article>
                </div>
            </div>
            <blockquote>Mais que um sistema, uma parceria que conecta pessoas e valoriza patrimônios.</blockquote>
        </div>
    </section>
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span><?= ($loginEnvironment ?? 'studio')==='helpdesk'?'MOVES HELP DESK':'MOVESOS' ?></span>
                <h2><?= ($loginEnvironment ?? 'studio')==='helpdesk'?'Help Desk':'Bem-vindo(a) de volta!' ?></h2>
                <p><?= ($loginEnvironment ?? 'studio')==='helpdesk'?'Acesse para atender e acompanhar chamados':'Acesse sua conta para continuar' ?></p>
            </header>
            <div class="ajax_response"><?= flash() ?></div>
            <form action="<?= url(($adminBase ?? '/studio').'/login') ?>" method="post">
                <?= csrf_input() ?>
                <label><span>E-mail</span><span class="connect-login__field"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6.5h18v12H3zM3.5 7l8.5 7 8.5-7"/></svg><input name="email" type="email" value="<?= htmlspecialchars($cookie ?? '') ?>" placeholder="seu@email.com" autocomplete="username" required></span></label>
                <label><span>Senha</span><span class="connect-login__field"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10h12v10H6zM8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/></svg><input id="studioPassword" name="password" type="password" placeholder="Sua senha" autocomplete="current-password" required><button class="connect-login__eye" type="button" aria-label="Mostrar senha" aria-controls="studioPassword"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5z"/><circle cx="12" cy="12" r="2.5"/></svg></button></span></label>
                <div class="connect-login__options"><label class="connect-login__remember"><input type="checkbox" name="save" value="1"><span>Lembrar meu e-mail</span></label><span>Área restrita</span></div>
                <button class="connect-login__submit" type="submit"><span>Entrar</span><strong>→</strong></button>
            </form>
            <footer><span class="connect-login__lock" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span> Ambiente seguro e monitorado</footer>
        </div>
        <a class="connect-login__back" href="<?= url('/') ?>">← Voltar ao site</a>
    </section>
</main>
<?php $this->start('scripts') ?><script>(function(){const b=document.querySelector('.connect-login__eye'),p=document.getElementById('studioPassword');if(!b||!p)return;b.addEventListener('click',function(){const v=p.type==='text';p.type=v?'password':'text';b.setAttribute('aria-label',v?'Mostrar senha':'Ocultar senha');b.classList.toggle('is-visible',!v);});})();</script><?php $this->stop() ?>
