<!doctype html>
<html lang="<?= htmlspecialchars(str_replace('_', '-', CONF_SITE_LANG)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head ?>
    <link rel="icon" href="<?= site_favicon_url() ?>">
    <link rel="stylesheet" href="<?= url('/container/shared/assets/organic/organic.min.css') ?>">
    <link rel="stylesheet" href="<?= url('/container/shared/assets/organic/compat-v1.css') ?>">
    <link rel="stylesheet" href="<?= theme('/assets/support.css?v=' . filemtime(__DIR__ . '/../assets/support.css'), 'support') ?>">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
</head>
<body class="support-site">
<a class="org-skip-link" href="#main-content">Ir para o conteúdo</a>
<header class="support-header">
    <a href="<?= url('/suporte') ?>"><img src="<?= site_logo_url() ?>" alt="<?= htmlspecialchars(CONF_SITE_NAME) ?>"></a>
    <a class="support-system-link" href="<?= url('/login') ?>">Acessar o sistema</a>
</header>
<section class="support-hero">
    <div class="support-hero-mark"><img src="<?= theme('/assets/images/marca_connect.svg') ?>" alt=""></div>
    <div>
        <span>SUPORTE MOVESOS</span>
        <h1>Como podemos ajudar?</h1>
        <form action="<?= url('/suporte/buscar') ?>" method="get">
            <input name="q" value="<?= htmlspecialchars((string)($search ?? '')) ?>" placeholder="Procure por respostas, funções ou dúvidas" minlength="2" required>
            <button aria-label="Buscar"><ion-icon name="search-outline"></ion-icon></button>
        </form>
    </div>
</section>
<main id="main-content" tabindex="-1"><?= $this->section('content') ?></main>
<footer class="support-footer">
    <div><img src="<?= site_logo_url() ?>" alt="<?= htmlspecialchars(CONF_SITE_NAME) ?>"><p>Suporte e Central de Conhecimento</p></div>
    <nav>
        <a href="<?= url('/politica-de-privacidade') ?>">Privacidade</a>
        <a href="<?= url('/termos-de-uso') ?>">Termos de uso</a>
        <span>© <?= date('Y') ?> <?= htmlspecialchars(CONF_SITE_NAME) ?></span>
    </nav>
</footer>
<script src="<?= url('/container/shared/assets/organic/organic.global.min.js') ?>"></script>
<script src="<?= url('/container/shared/assets/organic/compat-v1.js') ?>"></script>
<script src="<?= url('/container/shared/assets/js/helpdesk-widget.js') ?>" data-moves-helpdesk-widget defer></script>
<?= $this->section('scripts') ?>
</body>
</html>
