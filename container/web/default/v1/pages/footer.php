<!--FOOTER-->
<footer class="footer" id="contato">
    <div class="container footer__grid">
        <div><a class="logo" href="<?= url('/#home') ?>"><img src="<?= site_logo_url() ?>"
                                               alt="<?= htmlspecialchars(CONF_SITE_NAME) ?>"></a>
            <div class="footer-social">
                <?php if(CONF_SOCIAL_FACEBOOK_PAGE): ?><a target="_blank" rel="noopener noreferrer" class="icon-facebook" href="<?= htmlspecialchars(CONF_SOCIAL_FACEBOOK_PAGE) ?>" title="<?= htmlspecialchars(CONF_SITE_NAME) ?> no Facebook"></a><?php endif; ?>
                <?php if(CONF_SOCIAL_LINKEDIN_PAGE): ?><a target="_blank" rel="noopener noreferrer" class="icon-linkedin" href="<?= htmlspecialchars(CONF_SOCIAL_LINKEDIN_PAGE) ?>" title="<?= htmlspecialchars(CONF_SITE_NAME) ?> no LinkedIn"></a><?php endif; ?>
                <?php if(CONF_SOCIAL_INSTAGRAM_PAGE): ?><a target="_blank" rel="noopener noreferrer" class="icon-instagram" href="<?= htmlspecialchars(CONF_SOCIAL_INSTAGRAM_PAGE) ?>" title="<?= htmlspecialchars(CONF_SITE_NAME) ?> no Instagram"></a><?php endif; ?>
                <?php if(CONF_SOCIAL_YOUTUBE_PAGE): ?><a target="_blank" rel="noopener noreferrer" class="icon-youtube" href="<?= htmlspecialchars(CONF_SOCIAL_YOUTUBE_PAGE) ?>" title="<?= htmlspecialchars(CONF_SITE_NAME) ?> no YouTube"></a><?php endif; ?>
            </div>
        </div>
        <div><h4>Soluções</h4><a href="<?= url('/') ?>#solucao-financeira">Administração Financeira</a><a href="<?= url('/') ?>#solucao-administrativa">Assessoria
                Jurídica</a><a href="<?= url('/') ?>#solucao-operacional">Manutenção Predial</a><a href="<?= url('/') ?>#prestacao-de-contas">Prestação de Contas</a></div>
        <div><h4>Institucional</h4><a href="<?= url('/') ?>#about">Sobre nós</a><a href="<?= url('/') ?>#apcontrole">Diferenciais</a><a
                    href="<?= url('/') ?>#sucesso">Histórias de Sucesso</a><a href="<?= url('/') ?>#blog">Blog</a><a href="<?= url('/politica-de-privacidade') ?>">Política de Privacidade</a><a href="<?= url('/termos-de-uso') ?>">Termos de Uso</a>
        </div>
        <div><h4>Contato</h4><?php $phoneDigits=preg_replace('/\D+/', '', CONF_SITE_PHONE); ?><?php if(CONF_SITE_PHONE): ?><a href="tel:+<?= htmlspecialchars($phoneDigits) ?>"><?= htmlspecialchars(CONF_SITE_PHONE) ?></a><?php endif; ?><?php if(CONF_MAIL_SUPPORT): ?><a href="mailto:<?= htmlspecialchars(CONF_MAIL_SUPPORT) ?>"><?= htmlspecialchars(CONF_MAIL_SUPPORT) ?></a><?php endif; ?>
            <span><?= htmlspecialchars(trim(CONF_SITE_ADDR_STREET.', '.CONF_SITE_ADDR_NUMBER.(CONF_SITE_ADDR_COMPLEMENT?' - '.CONF_SITE_ADDR_COMPLEMENT:'').' - '.CONF_SITE_ADDR_DISTRICT.', '.CONF_SITE_ADDR_CITY.' - '.CONF_SITE_ADDR_STATE.', '.CONF_SITE_ADDR_ZIPCODE, ' ,-')) ?></span>
        </div>
    </div>
    <div class="footer__bottom">© <?= date('Y') ?> <?= htmlspecialchars(CONF_SITE_NAME) ?>. Todos os direitos reservados.</div>
</footer>
