<?php
/**
 * Lado da foto da recuperação de senha (ADR 0655): a mesma moldura do login,
 * com dicas de segurança no lugar dos textos de apresentação.
 */
$icons = [
    'mail' => '<path d="M6 12h36v24H6z"/><path d="m7 13 17 14 17-14"/>',
    'clock' => '<circle cx="24" cy="24" r="17"/><path d="M24 14v10l7 5"/>',
    'shield' => '<path d="M24 4 40 10v12c0 10-6.5 17-16 22C14.5 39 8 32 8 22V10z"/><path d="m17 24 5 5 10-11"/>',
    'key' => '<circle cx="16" cy="30" r="8"/><path d="m22 24 16-16M32 14l5 5M28 18l4 4"/>',
];
?>
<section class="connect-login__visual" aria-label="Dicas de segurança">
    <div class="connect-login__shade"></div>
    <div class="connect-login__visual-content">
        <a class="connect-login__brand" href="<?= url('/') ?>"><img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios"></a>
        <div class="connect-login__pitch">
            <span class="connect-login__line"></span>
            <h1><?= $visualTitle ?></h1>
            <p><?= $visualLead ?></p>
            <div class="connect-login__benefits">
                <?php foreach ($tips as [$icon, $title, $text]): ?>
                    <article><span class="connect-login__benefit-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><?= $icons[$icon] ?></svg></span><h2><?= $title ?></h2><p><?= $text ?></p></article>
                <?php endforeach; ?>
            </div>
        </div>
        <blockquote>A Connect nunca pede a sua senha por telefone, e-mail ou WhatsApp.</blockquote>
    </div>
</section>
