<?php
/**
 * Banner de cookies (LGPD, ADR 0600). Nenhum script ou conteúdo de terceiros carrega antes da escolha.
 * "Recusar", "Escolher" e "Aceitar" têm o mesmo tamanho e destaque. A escolha fica no cookie necessário
 * connect_cookie_consent e a prova vai para privacy_consents (POST /cookies). O link "Cookies" do rodapé reabre.
 */
use Source\Services\Privacy\LegalDocuments;

$cookieCategories = LegalDocuments::cookieCategories();
?>
<section class="cookie-consent" id="cookie-consent" role="region" aria-labelledby="cookie-consent-title" hidden
         data-cookie-consent
         data-cookie-name="<?= htmlspecialchars(LegalDocuments::COOKIE_NAME) ?>"
         data-cookie-version="<?= htmlspecialchars(LegalDocuments::COOKIES) ?>"
         data-cookie-endpoint="<?= htmlspecialchars(url('/cookies')) ?>"
         <?php if (LegalDocuments::analyticsId() !== ''): ?>data-ga-id="<?= htmlspecialchars(LegalDocuments::analyticsId()) ?>"<?php endif; ?>>
    <form class="cookie-consent__box" data-cookie-form>
        <?= csrf_input() ?>
        <h2 id="cookie-consent-title">Cookies neste site</h2>
        <p>Usamos só os cookies necessários para o site funcionar. Os opcionais abaixo ficam desligados até você escolher.
            Veja a <a href="<?= url('/politica-de-privacidade') ?>#cookies">Política de Privacidade</a>.</p>
        <fieldset class="cookie-consent__choices" data-cookie-choices hidden>
            <legend>Escolha o que pode carregar</legend>
            <label><input type="checkbox" checked disabled> Necessários (sessão e segurança do formulário), sempre ligados</label>
            <?php foreach ($cookieCategories as $key => $label): ?>
                <label><input type="checkbox" name="<?= htmlspecialchars($key) ?>" value="1" data-cookie-category="<?= htmlspecialchars($key) ?>"> <?= htmlspecialchars($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="cookie-consent__actions">
            <button type="button" class="cookie-consent__btn" data-cookie-action="reject">Recusar</button>
            <button type="button" class="cookie-consent__btn" data-cookie-action="choose" aria-expanded="false">Escolher</button>
            <button type="button" class="cookie-consent__btn" data-cookie-action="save" hidden>Salvar escolha</button>
            <button type="button" class="cookie-consent__btn" data-cookie-action="accept">Aceitar</button>
        </div>
    </form>
</section>
