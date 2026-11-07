<?php
/**
 * Navbar: menu móvel, título e breadcrumbs, pesquisa de páginas, notificações e perfil.
 * A pesquisa procura nas páginas do catálogo (lado do cliente); não consulta dados.
 *
 * @var string $title
 * @var array $notifications
 * @var bool $demo
 * @var callable $icon
 */
$initial = mb_strtoupper(mb_substr(trim((string)($user->first_name ?? 'C')), 0, 1));
?>
<header class="erp-navbar">
    <button type="button" class="erp-icon-button erp-navbar__menu" data-erp-nav-open aria-label="Abrir menu" aria-controls="erp-sidebar" aria-expanded="false"><?= $icon('menu') ?></button>
    <div class="erp-navbar__title">
        <strong><?= $this->e($title) ?></strong>
        <?php $this->insert('components/breadcrumbs/breadcrumbs'); ?>
    </div>
    <div class="erp-search" role="search">
        <?= $icon('search', 'erp-icon--sm') ?>
        <label class="erp-sr-only" for="erp-search-input">Pesquisar páginas do ERP</label>
        <input id="erp-search-input" type="search" placeholder="Pesquisar páginas (Ctrl + K)" autocomplete="off" data-erp-search aria-controls="erp-search-results" aria-expanded="false">
        <div class="erp-search__results" id="erp-search-results" role="listbox" hidden></div>
    </div>
    <div class="erp-navbar__actions">
        <details class="erp-dropdown" data-erp-dropdown>
            <summary class="erp-icon-button" aria-label="Notificações"><?= $icon('bell') ?><?php if ($notifications): ?><span class="erp-icon-button__dot"></span><?php endif; ?></summary>
            <div class="erp-dropdown__panel">
                <h2>Notificações <?php if ($demo && $notifications): ?><span class="erp-demo-flag">Demonstração</span><?php endif; ?></h2>
                <?php if (!$notifications): ?>
                    <p class="erp-dropdown__item"><span>Nenhuma notificação no momento.</span></p>
                <?php endif; ?>
                <?php foreach ($notifications as $notification): ?>
                    <a class="erp-dropdown__item" href="<?= url($notification['href']) ?>"><?= $icon('info', 'erp-icon--sm') ?><span><?= $this->e($notification['title']) ?><small><?= $this->e($notification['text']) ?></small></span></a>
                <?php endforeach; ?>
            </div>
        </details>
        <a class="erp-icon-button" href="<?= url('/erp/configuracoes') ?>" aria-label="Configurações" title="Configurações"><?= $icon('settings') ?></a>
        <details class="erp-dropdown" data-erp-dropdown>
            <summary class="erp-user-chip" aria-label="Menu do usuário"><span class="erp-avatar" aria-hidden="true"><?= $this->e($initial) ?></span><span><?= $this->e($user->first_name ?? '') ?></span></summary>
            <div class="erp-dropdown__panel">
                <h2><?= $this->e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))) ?><br><small><?= $this->e($user->email ?? '') ?></small></h2>
                <a class="erp-dropdown__item" href="<?= url('/erp/configuracoes/usuarios-do-sistema') ?>"><?= $icon('user', 'erp-icon--sm') ?><span>Meu perfil</span></a>
                <a class="erp-dropdown__item" href="<?= url('/studio/seguranca/2fa') ?>"><?= $icon('shield', 'erp-icon--sm') ?><span>Segurança e 2FA<small>Gerenciado no Studio</small></span></a>
                <a class="erp-dropdown__item" href="<?= url('/erp/configuracoes') ?>"><?= $icon('settings', 'erp-icon--sm') ?><span>Configurações</span></a>
                <div class="erp-dropdown__sep"></div>
                <form method="post" action="<?= url('/erp/sair') ?>">
                    <?= csrf_input() ?>
                    <button type="submit" class="erp-dropdown__item"><?= $icon('logout', 'erp-icon--sm') ?><span>Sair</span></button>
                </form>
            </div>
        </details>
    </div>
</header>
