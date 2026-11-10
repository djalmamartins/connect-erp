<?php
/**
 * Central de Ajuda pública (/suporte), ADR 0141. Identidade Connect: logotipo dourado, verde
 * profundo do ERP e Inter. Estrutura inspirada em centrais de ajuda de mercado (hero com busca,
 * canais, artigos com índice), sem copiar código nem arte de terceiros.
 */
$contact = \Source\Services\Help\SupportContact::data();
$logo = url('/container/web/default/v1/assets/images/logo-connect-condominios.svg');
$logoLight = url('/container/web/default/v1/assets/svg/logo-connect-footer.svg');
$cssFile = __DIR__ . '/../assets/support.css';
$residentsUrl = \Source\Services\Environments::url('residents', '/app');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head ?>
    <link rel="icon" type="image/svg+xml" href="<?= url('/container/web/support/v1/assets/images/favicon-ajuda.svg') ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="<?= url('/container/web/support/v1/assets/images/favicon-ajuda.png') ?>">
    <link rel="apple-touch-icon" href="<?= url('/container/web/support/v1/assets/images/favicon-ajuda.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= url('/container/web/support/v1/assets/support.css?v=' . filemtime($cssFile)) ?>">
</head>
<body class="hc">
<a class="hc-skip" href="#conteudo">Ir para o conteúdo</a>
<header class="hc-top">
    <div class="hc-wrap hc-top__bar">
        <a class="hc-brand" href="<?= url('/suporte') ?>" aria-label="Central de Ajuda Connect">
            <img src="<?= $logo ?>" alt="Connect Condomínios" width="150" height="44">
            <span class="hc-brand__tag">Ajuda</span>
        </a>
        <nav class="hc-top__nav" aria-label="Central de Ajuda">
            <a href="<?= url('/suporte#assuntos') ?>">Assuntos</a>
            <a href="<?= url('/suporte#mensagem') ?>">Fale com a gente</a>
            <a class="hc-top__site" href="<?= url('/') ?>"><?= \Source\Services\Help\Icons::svg('home-outline') ?>Ir para o site</a>
        </nav>
        <a class="hc-btn hc-btn--dark" href="<?= htmlspecialchars($contact['system_url']) ?>"><?= \Source\Services\Help\Icons::svg('log-in-outline') ?>Acessar o sistema</a>
    </div>
</header>

<main id="conteudo" tabindex="-1"><?= $this->section('content') ?></main>

<?php if (empty($hideHelpFooter)): ?>
<section class="hc-notfound" aria-labelledby="hc-notfound-title">
    <div class="hc-wrap hc-notfound__grid">
        <div>
            <h2 id="hc-notfound-title">Não encontrou o que procurava?</h2>
            <p>Fale com a gente pelos nossos canais.</p>
            <div class="hc-notfound__channels">
                <a class="hc-mini" href="<?= url('/suporte#mensagem') ?>"><?= \Source\Services\Help\Icons::svg('chatbox-ellipses-outline') ?><span>Abrir chamado</span></a>
                <button class="hc-mini" type="button" data-hc-chat><?= \Source\Services\Help\Icons::svg('headset-outline') ?><span>Falar conosco</span></button>
                <?php if ($contact['phone'] || $contact['email']): ?>
                    <div class="hc-mini hc-mini--wide">
                        <?php if ($contact['phone']): ?><strong><?= htmlspecialchars($contact['phone']) ?><?php if ($contact['whatsapp_url']): ?> <?= \Source\Services\Help\Icons::svg('logo-whatsapp') ?><?php endif; ?></strong><?php endif; ?>
                        <?php if ($contact['email']): ?><a href="mailto:<?= htmlspecialchars($contact['email']) ?>"><?= htmlspecialchars($contact['email']) ?></a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($contact['social']): ?>
            <div class="hc-social"><strong>Acompanhe nas redes</strong><div><?php foreach ($contact['social'] as $social): ?><a href="<?= htmlspecialchars($social['url']) ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($social['label']) ?>"><?= \Source\Services\Help\Icons::svg($social['icon']) ?></a><?php endforeach; ?></div></div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<footer class="hc-footer">
    <div class="hc-wrap">
        <svg class="hc-footer__marks" viewBox="0 0 230 56" aria-hidden="true">
            <defs><path id="hc-mark" d="M35.6,14.8c-10.4,2.1-18.2,11.3-18.2,22.3c0,6.1,2.4,11.7,6.4,15.8l16.4,16.5l16.4-16.5c3.9-4.1,6.4-9.7,6.4-15.8c0-12.1-9.4-21.9-21.3-22.7v8.9c0,0,0,0,0,0h0v4.6l2.7-4c5.5,1.8,9.4,6.9,9.4,13c0,3.7-1.4,7-3.8,9.4l0,0l-9.9,9.9l-9.9-9.9l0,0c-2.4-2.5-3.8-5.8-3.8-9.4c0-7,5.3-12.8,12.1-13.6v-8.9c0,0,0,0,0,0v-4.2L35.6,14.8L35.6,14.8z"/></defs>
            <?php foreach ([0.06, 0.12, 0.2, 0.32, 0.5, 0.72, 1] as $i => $opacity): ?>
                <use href="#hc-mark" class="hc-footer__mark" transform="translate(<?= $i * 26 - 17 ?> -14)" opacity="<?= $opacity ?>"/>
            <?php endforeach; ?>
        </svg>
        <div class="hc-footer__bottom">
            <a href="<?= url('/') ?>"><img src="<?= $logoLight ?>" alt="Connect Condomínios" width="120" height="36"></a>
            <nav class="hc-footer__links" aria-label="Outros acessos">
                <a href="<?= url('/') ?>"><?= \Source\Services\Help\Icons::svg('globe-outline') ?>Voltar para o site</a>
                <a href="<?= htmlspecialchars($residentsUrl) ?>"><?= \Source\Services\Help\Icons::svg('home-outline') ?>App do morador</a>
                <a href="<?= htmlspecialchars($contact['system_url']) ?>"><?= \Source\Services\Help\Icons::svg('log-in-outline') ?>Connect ERP</a>
            </nav>
        </div>
        <div class="hc-footer__legal">
            <span>© <?= date('Y') ?> Connect Condomínios. Todos os direitos reservados.</span>
            <nav><a href="<?= url('/politica-de-privacidade') ?>">Privacidade</a><a href="<?= url('/termos-de-uso') ?>">Termos de uso</a></nav>
            <a class="hc-totop" href="#conteudo">Voltar ao topo <?= \Source\Services\Help\Icons::svg('arrow-up-outline') ?></a>
        </div>
    </div>
</footer>
<script src="<?= url('/container/shared/assets/js/helpdesk-widget.js') ?>" data-moves-helpdesk-widget data-color="#0d2e27" data-label="Ajuda" defer></script>
<script src="<?= url('/container/web/support/v1/assets/support.js?v=' . filemtime(__DIR__ . '/../assets/support.js')) ?>" defer></script>
<?= $this->section('scripts') ?>
</body>
</html>
