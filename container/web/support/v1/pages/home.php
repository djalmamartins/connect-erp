<?php
$this->layout('layouts/theme');
$contact = \Source\Services\Help\SupportContact::data();
?>
<section class="hc-hero" aria-labelledby="hc-hero-title">
    <div class="hc-hero__art" aria-hidden="true">
        <svg class="hc-hero__arcs hc-hero__arcs--left" viewBox="0 0 220 160"><path d="M10 150V96a50 50 0 0 1 100 0v54M110 150V70a50 50 0 0 1 100 0v80" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        <svg class="hc-hero__arcs hc-hero__arcs--right" viewBox="0 0 220 160"><path d="M10 150a60 60 0 0 1 120 0M90 150V80a45 45 0 0 1 90 0v70" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    </div>
    <div class="hc-wrap hc-hero__inner">
        <span class="hc-pill"><?= \Source\Services\Help\Icons::svg('headset-outline') ?>Fale com a gente</span>
        <h1 id="hc-hero-title">Como podemos te ajudar hoje?</h1>
        <p>Esta é a central de atendimento da Connect Condomínios.</p>
        <form class="hc-search" action="<?= url('/suporte/buscar') ?>" method="get" role="search">
            <?= \Source\Services\Help\Icons::svg('search-outline') ?>
            <input name="q" type="search" placeholder="Pesquise uma dúvida: boleto, cadastrar unidade, acordo…" minlength="2" required aria-label="Pesquisar na Central de Ajuda">
            <button class="hc-btn hc-btn--gold" type="submit">Buscar</button>
        </form>
    </div>
</section>

<section class="hc-wrap hc-channels" aria-label="Canais de atendimento">
    <button class="hc-channel" type="button" data-hc-chat>
        <i><?= \Source\Services\Help\Icons::svg('chatbubbles-outline') ?></i>
        <strong>Conversar no chat</strong>
        <span>Abra um chamado ou acompanhe o seu protocolo.</span>
    </button>
    <?php if ($contact['whatsapp_url']): ?>
        <a class="hc-channel" href="<?= htmlspecialchars($contact['whatsapp_url']) ?>" target="_blank" rel="noopener">
            <i><?= \Source\Services\Help\Icons::svg('logo-whatsapp') ?></i>
            <strong>Falar no WhatsApp</strong>
            <span>Atendimento amigável e direto.</span>
        </a>
    <?php endif; ?>
    <a class="hc-channel" href="#assuntos">
        <i><?= \Source\Services\Help\Icons::svg('help-buoy-outline') ?></i>
        <strong>Central de ajuda</strong>
        <span>Tutoriais com as telas do sistema.</span>
    </a>
    <a class="hc-channel" href="#mensagem">
        <i><?= \Source\Services\Help\Icons::svg('happy-outline') ?></i>
        <strong>Abra um chamado</strong>
        <span>Nossa equipe responde por e-mail.</span>
    </a>
</section>

<section class="hc-wrap hc-section" id="assuntos" aria-labelledby="hc-topics-title">
    <header class="hc-section__head">
        <small>Explore por assunto</small>
        <h2 id="hc-topics-title">Encontre o passo a passo de cada área</h2>
        <p>Cada tela do sistema tem um tutorial com prints reais e marcações mostrando onde clicar.</p>
    </header>
    <div class="hc-topics">
        <?php foreach ($categories as $category): $total = $counts[(int)$category->id] ?? 0; if (!$total) { continue; } ?>
            <a class="hc-topic" href="<?= url('/suporte/' . $category->uri) ?>">
                <i><?= \Source\Services\Help\Icons::svg($category->icon) ?></i>
                <span><strong><?= htmlspecialchars($category->title) ?></strong><small><?= htmlspecialchars((string)$category->description) ?></small></span>
                <em><?= $total ?> <?= $total === 1 ? 'tutorial' : 'tutoriais' ?> <?= \Source\Services\Help\Icons::svg('arrow-forward-outline') ?></em>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($popular): ?>
<section class="hc-wrap hc-section hc-section--tight" aria-labelledby="hc-popular-title">
    <header class="hc-section__head hc-section__head--row">
        <div><small>Mais acessados</small><h2 id="hc-popular-title">Tutoriais populares</h2></div>
    </header>
    <div class="hc-popular">
        <?php foreach ($popular as $item): $itemCategory = $item->category(); if (!$itemCategory) { continue; } ?>
            <a href="<?= url('/suporte/' . $itemCategory->uri . '/' . $item->uri) ?>">
                <?= \Source\Services\Help\Icons::svg('document-text-outline') ?>
                <span><strong><?= htmlspecialchars($item->title) ?></strong><small><?= htmlspecialchars($itemCategory->title) ?></small></span>
                <?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="hc-wrap hc-contact" id="mensagem" aria-labelledby="hc-message-title">
    <div class="hc-contact__form">
        <h2 id="hc-message-title">Deixe a sua mensagem</h2>
        <p class="hc-muted">Vamos te responder o quanto antes. A mensagem vira um chamado no nosso Help Desk, com protocolo.</p>
        <div class="hc-feedback<?= $sent ? ' is-success' : '' ?>" data-hc-feedback role="status" aria-live="polite"><?= $sent ? 'Recebemos a sua mensagem. O protocolo é ' . htmlspecialchars($sent) . ' e a resposta chega no seu e-mail.' : '' ?></div>
        <form class="hc-form" action="<?= url('/suporte/mensagem') ?>" method="post" data-hc-message novalidate>
            <input type="hidden" name="widget_token" value="<?= htmlspecialchars($formToken) ?>">
            <label class="hc-honeypot" aria-hidden="true">Site<input name="website" tabindex="-1" autocomplete="off"></label>
            <div class="hc-form__row">
                <label><span>Qual o seu nome?</span><input name="name" autocomplete="name" maxlength="160" placeholder="Nome" required></label>
                <label><span>Qual o seu telefone?</span><input name="phone" type="tel" autocomplete="tel" maxlength="30" placeholder="(00) 00000-0000" data-hc-phone></label>
            </div>
            <label><span>Qual seu melhor e-mail?</span><input name="email" type="email" autocomplete="email" maxlength="190" placeholder="E-mail" required></label>
            <label><span>Qual o assunto?</span><input name="subject" maxlength="180" minlength="4" placeholder="Digite o assunto da mensagem" required></label>
            <label><span>Como podemos te ajudar?</span><textarea name="message" minlength="10" rows="5" placeholder="Conte o que aconteceu, em qual tela e o que você esperava" required></textarea></label>
            <div class="hc-form__foot">
                <label class="hc-check"><input type="checkbox" name="whatsapp" value="1"><span>Aceito resposta por WhatsApp</span></label>
                <button class="hc-btn hc-btn--gold hc-btn--lg" type="submit">Enviar mensagem</button>
            </div>
        </form>
    </div>
    <aside class="hc-contact__details" aria-labelledby="hc-details-title">
        <h2 id="hc-details-title">Detalhes de contato</h2>
        <dl>
            <div><dt><?= \Source\Services\Help\Icons::svg('time-outline') ?>Horário de atendimento</dt><dd><?= htmlspecialchars($contact['hours']) ?></dd></div>
            <?php if ($contact['address']): ?><div><dt><?= \Source\Services\Help\Icons::svg('location-outline') ?>Endereço</dt><dd><?= htmlspecialchars($contact['address']) ?></dd></div><?php endif; ?>
            <?php if ($contact['email']): ?><div><dt><?= \Source\Services\Help\Icons::svg('mail-outline') ?>E-mail</dt><dd><a href="mailto:<?= htmlspecialchars($contact['email']) ?>"><?= htmlspecialchars($contact['email']) ?></a></dd></div><?php endif; ?>
            <?php if ($contact['phone']): ?><div><dt><?= \Source\Services\Help\Icons::svg('call-outline') ?>Telefone</dt><dd><?= htmlspecialchars($contact['phone']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($contact['whatsapp_url']): ?><a class="hc-whats" href="<?= htmlspecialchars($contact['whatsapp_url']) ?>" target="_blank" rel="noopener"><?= \Source\Services\Help\Icons::svg('logo-whatsapp') ?>Estamos no WhatsApp</a><?php endif; ?>
    </aside>
</section>

<section class="hc-wrap">
    <div class="hc-cta">
        <div class="hc-cta__text">
            <small>Já é cliente da Connect Condomínios?</small>
            <h2>Acesse o Connect ERP</h2>
            <p>Condomínios, cobrança, prestação de contas e atendimento em um só lugar.</p>
            <a class="hc-btn hc-btn--gold" href="<?= htmlspecialchars($contact['system_url']) ?>">Entrar no sistema <?= \Source\Services\Help\Icons::svg('arrow-forward-outline') ?></a>
        </div>
        <?php if ($showcase): ?><div class="hc-cta__shot"><img src="<?= htmlspecialchars($showcase) ?>" alt="Painel do Connect ERP" loading="lazy" width="1440" height="900"></div><?php endif; ?>
    </div>
</section>
