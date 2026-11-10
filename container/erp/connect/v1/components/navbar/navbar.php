<?php
/**
 * Navbar: menu móvel, título e breadcrumbs, pesquisa de páginas, suporte, notificações e perfil.
 * O sino mostra as notificações reais da pessoa, as mesmas do Studio e do Help Desk (ADR 0142).
 * A pesquisa procura nas páginas do catálogo (lado do cliente); não consulta dados.
 *
 * @var string $title
 * @var array $notifications
 * @var bool $demo
 * @var callable $icon
 * @var list<array{id:int,name:string}> $condoOptions  condomínios do escopo do usuário (CondominiumScope)
 * @var ?array{id:int,name:string,slug:string} $condoCurrent  condomínio em contexto (slug na URL, ADR 0030; null = todos)
 */
$initial = mb_strtoupper(mb_substr(trim((string)($user->first_name ?? 'C')), 0, 1));
?>
<header class="erp-navbar">
    <button type="button" class="erp-icon-button erp-navbar__menu" data-erp-nav-open aria-label="Abrir menu" aria-controls="erp-sidebar" aria-expanded="false"><?= $icon('menu') ?></button>
    <div class="erp-navbar__title">
        <strong><?= $this->e($title) ?></strong>
        <?php $this->insert('components/breadcrumbs/breadcrumbs'); ?>
    </div>
    <form class="erp-condo-switch" method="post" action="<?= erp_url('/contexto/condominio') ?>" data-erp-condo-switch>
        <?= csrf_input() ?>
        <input type="hidden" name="back" value="<?= $this->e(\Source\Support\Erp\CondominiumContext::relativePath()) ?>">
        <span class="erp-condo-switch__icon" aria-hidden="true"><?= $icon('building', 'erp-icon--sm') ?></span>
        <label class="erp-condo-switch__label" for="erp-condo-select">Condomínio</label>
        <select id="erp-condo-select" name="condominium" class="erp-condo-switch__select"<?= $condoOptions === [] ? ' disabled' : '' ?>>
            <?php if ($condoOptions === []): ?>
                <option value="0">Nenhum condomínio vinculado</option>
            <?php else: ?>
                <option value="0"<?= $condoCurrent === null ? ' selected' : '' ?>>Todos os condomínios</option>
                <?php foreach ($condoOptions as $condo): ?>
                    <option value="<?= (int)$condo['id'] ?>"<?= ($condoCurrent['id'] ?? null) === (int)$condo['id'] ? ' selected' : '' ?>><?= $this->e($condo['name']) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <noscript><button type="submit" class="erp-btn erp-btn--sm">Trocar</button></noscript>
    </form>
    <div class="erp-navsearch" data-erp-navsearch>
        <button type="button" class="erp-icon-button" data-erp-navsearch-toggle aria-label="Pesquisar páginas (Ctrl + K)" title="Pesquisar páginas (Ctrl + K)" aria-controls="erp-navsearch-panel" aria-expanded="false"><?= $icon('search') ?></button>
        <div class="erp-navsearch__panel" id="erp-navsearch-panel" role="search" hidden>
            <div class="erp-search">
                <?= $icon('search', 'erp-icon--sm') ?>
                <label class="erp-sr-only" for="erp-search-input">Pesquisar páginas do ERP</label>
                <input id="erp-search-input" type="search" placeholder="Pesquisar páginas" autocomplete="off" data-erp-search aria-controls="erp-search-results" aria-expanded="false">
                <div class="erp-search__results" id="erp-search-results" role="listbox" hidden></div>
            </div>
        </div>
    </div>
    <div class="erp-navbar__actions">
        <details class="erp-dropdown" data-erp-dropdown>
            <summary class="erp-icon-button" aria-label="Suporte" title="Suporte"><?= $icon('help') ?></summary>
            <div class="erp-dropdown__panel">
                <h2>Suporte</h2>
                <?php foreach ($supportMenu ?? [] as $support): ?>
                    <a class="erp-dropdown__item" href="<?= $this->e($support['href']) ?>"><?= $icon($support['icon'], 'erp-icon--sm') ?><span><?= $this->e($support['label']) ?><small><?= $this->e($support['text']) ?></small></span></a>
                <?php endforeach; ?>
            </div>
        </details>
        <?php $unread = (int)($notificationCount ?? 0); ?>
        <details class="erp-dropdown" data-erp-dropdown>
            <summary class="erp-icon-button" aria-label="Notificações<?= $unread > 0 ? ' (' . $unread . ' não lidas)' : '' ?>"><?= $icon('bell') ?><?php if ($unread > 0): ?><span class="erp-icon-button__count" aria-hidden="true"><?= $unread > 99 ? '99+' : $unread ?></span><?php elseif ($notifications && empty($notificationsReal)): ?><span class="erp-icon-button__dot"></span><?php endif; ?></summary>
            <div class="erp-dropdown__panel erp-dropdown__panel--wide">
                <h2>Notificações <?php if ($demo && $notifications && empty($notificationsReal)): ?><span class="erp-demo-flag">Demonstração</span><?php endif; ?></h2>
                <?php if (!$notifications): ?>
                    <p class="erp-dropdown__item"><span>Nenhuma notificação no momento.</span></p>
                <?php endif; ?>
                <?php foreach ($notifications as $notification): ?>
                    <a class="erp-dropdown__item<?= !empty($notification['unread']) ? ' is-unread' : '' ?>" href="<?= erp_url($notification['href']) ?>"><?= $icon('info', 'erp-icon--sm') ?><span><?= $this->e($notification['title']) ?><small><?= $this->e($notification['text']) ?></small><?php if (!empty($notification['when'])): ?><time><?= $this->e($notification['when']) ?></time><?php endif; ?></span></a>
                <?php endforeach; ?>
                <?php if ($unread > 0): ?>
                    <div class="erp-dropdown__sep"></div>
                    <form method="post" action="<?= erp_url('/notificacoes/lidas') ?>">
                        <?= csrf_input() ?>
                        <input type="hidden" name="back" value="<?= $this->e(\Source\Support\Erp\CondominiumContext::relativePath()) ?>">
                        <button type="submit" class="erp-dropdown__item"><?= $icon('check', 'erp-icon--sm') ?><span>Marcar todas como lidas</span></button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
        <?php if (!empty($currentPageId)): ?><a class="erp-icon-button" href="<?= \Source\Services\Environments::url('site', '/suporte/tela/' . rawurlencode((string)$currentPageId)) ?>" target="_blank" rel="noopener" aria-label="Ajuda desta página" title="Ajuda desta página" data-erp-help><?= $icon('help') ?></a><?php endif; ?>
        <a class="erp-icon-button" href="<?= erp_url('/configuracoes') ?>" aria-label="Configurações" title="Configurações"><?= $icon('settings') ?></a>
        <details class="erp-dropdown" data-erp-dropdown>
            <summary class="erp-user-chip" aria-label="Menu do usuário"><span class="erp-avatar" aria-hidden="true"><?= $this->e($initial) ?></span><span><?= $this->e($user->first_name ?? '') ?></span></summary>
            <div class="erp-dropdown__panel">
                <h2><?= $this->e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))) ?><br><small><?= $this->e($user->email ?? '') ?></small></h2>
                <a class="erp-dropdown__item" href="<?= erp_url('/configuracoes/usuarios-do-sistema') ?>"><?= $icon('user', 'erp-icon--sm') ?><span>Meu perfil</span></a>
                <a class="erp-dropdown__item" href="<?= erp_url('/seguranca') ?>"><?= $icon('shield', 'erp-icon--sm') ?><span>Segurança e 2FA<small>Verificação em duas etapas</small></span></a>
                <a class="erp-dropdown__item" href="<?= erp_url('/configuracoes') ?>"><?= $icon('settings', 'erp-icon--sm') ?><span>Configurações</span></a>
                <div class="erp-dropdown__sep"></div>
                <form method="post" action="<?= erp_url('/sair') ?>">
                    <?= csrf_input() ?>
                    <button type="submit" class="erp-dropdown__item"><?= $icon('logout', 'erp-icon--sm') ?><span>Sair</span></button>
                </form>
            </div>
        </details>
    </div>
</header>
