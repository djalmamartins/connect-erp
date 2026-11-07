

<section class="condominio-cta">
    <div class="cta-content">

        <div class="cta-icon">
            <svg viewBox="0 0 64 64" aria-hidden="true">
                <rect x="8" y="12" width="48" height="44" rx="4"></rect>

                <line x1="8" y1="24" x2="56" y2="24"></line>

                <line x1="20" y1="7" x2="20" y2="17"></line>
                <line x1="44" y1="7" x2="44" y2="17"></line>

                <circle cx="19" cy="34" r="2"></circle>
                <circle cx="32" cy="34" r="2"></circle>
                <circle cx="45" cy="34" r="2"></circle>

                <circle cx="19" cy="45" r="2"></circle>
                <circle cx="32" cy="45" r="2"></circle>
                <circle cx="45" cy="45" r="2"></circle>
            </svg>
        </div>

        <div class="cta-text">
            <h2>Vamos conversar sobre o seu condomínio?</h2>

            <p>
                Solicite uma proposta personalizada e descubra como podemos<br class="desktop">
                gerar mais eficiência, economia e valorização.
            </p>
        </div>

        <div class="cta-actions">
            <a href="<?= url("/solicite-sua-proposta"); ?>" class="btn btn-primary">
                Solicitar proposta
            </a>

            <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/\D+/', '', CONF_SITE_WHATSAPP)) ?>" target="_blank" rel="noopener noreferrer" class="link icon icon-whatsapp">
                Falar no WhatsApp
            </a>
        </div>

    </div>
</section>
