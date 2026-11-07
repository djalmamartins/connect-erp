<!--HEADER-->
<header class="main_header">
    <div class="container">
        <div class="main_header_logo">
            <a class="transition" title="Home" href="<?= url(); ?>"><img src="<?= site_logo_url() ?>" alt="<?= htmlspecialchars(CONF_SITE_NAME) ?>"></a>
        </div>

        <nav class="main_header_nav">
            <button class="main_header_nav_mobile j_menu_mobile_open icon-menu icon-notext radius transition"
                    type="button" aria-label="Abrir menu" aria-controls="sales-menu" aria-expanded="false"></button>
            <div class="main_header_nav_links j_menu_mobile_tab" id="sales-menu">
                <button class="main_header_nav_mobile_close j_menu_mobile_close icon-error icon-notext transition"
                        type="button" aria-label="Fechar menu"></button>


                <a class="link" href="<?= url(); ?>">
                    Voltar ao site
                </a>

                <?php $whatsappDigits=preg_replace('/\D+/', '', CONF_SITE_WHATSAPP); ?>
                <?php if($whatsappDigits): ?><a class="link icon icon-whatsapp" href="https://wa.me/<?= htmlspecialchars($whatsappDigits) ?>" target="_blank" rel="noopener noreferrer">
                    Falar no WhatsApp
                </a><?php endif; ?>
            </div>
        </nav>
    </div>
</header>
