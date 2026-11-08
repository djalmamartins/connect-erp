<?php
/**
 * Sidebar do ERP: grupos do catálogo em <details>, item ativo e modo recolhido.
 * Grupos longos (mais de 14 páginas) mostram a visão geral e as seções, para o menu
 * caber na tela; todas as páginas continuam a um clique na visão geral do módulo.
 *
 * @var array $catalog
 * @var ?string $currentGroup
 * @var ?string $currentPageId
 * @var callable $icon
 * @var callable $anchor
 * @var callable $canSee  esconde páginas sem a permissão própria (a rota valida de novo)
 */
$pagesByGroup = [];
foreach ($catalog['pages'] as $id => $page) {
    if ($canSee($id)) {
        $pagesByGroup[$page['group']][$id] = $page;
    }
}
$currentSection = $currentPageId !== null ? ($catalog['pages'][$currentPageId]['section'] ?? null) : null;
$initial = mb_strtoupper(mb_substr(trim((string)($user->first_name ?? 'C')), 0, 1));
?>
<aside class="erp-sidebar" id="erp-sidebar" aria-label="Navegação do ERP">
    <div class="erp-sidebar__head">
        <a class="erp-brand" href="<?= url('/erp/dashboard') ?>" aria-label="Connect ERP — início">
            <img class="erp-brand__logo" src="<?= $this->e(function_exists('site_logo_url') ? site_logo_url() : theme('/assets/images/logo-connect-condominios.svg')) ?>" alt="Connect Condomínios" width="152" height="40">
            <img class="erp-brand__mark" src="<?= theme('/assets/images/marca_connect.svg') ?>" alt="" width="34" height="34">
        </a>
        <button type="button" class="erp-sidebar__collapse" data-erp-collapse aria-label="Recolher menu" aria-controls="erp-sidebar" aria-expanded="true"><?= $icon('chevron-left') ?></button>
        <button type="button" class="erp-sidebar__close" data-erp-nav-close aria-label="Fechar menu"><?= $icon('close') ?></button>
    </div>
    <div class="erp-sidebar__context">
        <small>Administradora</small>
        <strong>Connect · ERP</strong>
    </div>
    <nav class="erp-nav" aria-label="Menu principal">
        <?php foreach ($catalog['groups'] as $groupKey => $group):
            $pages = $pagesByGroup[$groupKey] ?? [];
            $isActive = $currentGroup === $groupKey;
            if ($groupKey === 'dashboard'): ?>
                <a class="erp-nav__link" href="<?= url('/erp/dashboard') ?>"<?= $currentPageId === 'AP-001' ? ' aria-current="page"' : '' ?> title="Dashboard"><?= $icon($group['icon']) ?><span class="erp-nav__label">Dashboard</span></a>
                <?php continue;
            endif;
            $long = count($pages) > 14;
            $hubHref = '/erp' . $group['prefix'];
            $hasHub = !in_array($group['prefix'], array_column($catalog['pages'], 'route'), true); ?>
            <details class="erp-nav__group" data-erp-group="<?= $this->e($groupKey) ?>"<?= $isActive ? ' open' : '' ?>>
                <summary<?= $isActive ? ' class="is-active"' : '' ?> title="<?= $this->e($group['label']) ?>"><?= $icon($group['icon']) ?><span class="erp-nav__label"><?= $this->e($group['label']) ?></span></summary>
                <div class="erp-nav__sub">
                    <?php if ($hasHub): ?>
                        <a href="<?= url($hubHref) ?>"<?= $isActive && $currentPageId === null ? ' aria-current="page"' : '' ?>>Visão geral</a>
                    <?php endif; ?>
                    <?php if ($long):
                        $sections = array_values(array_unique(array_filter(array_column($pages, 'section'))));
                        foreach ($sections as $section): ?>
                            <a href="<?= url($hubHref) ?>#<?= $anchor($section) ?>"<?= $isActive && $currentSection === $section ? ' aria-current="page"' : '' ?>><?= $this->e($section) ?></a>
                        <?php endforeach;
                    else:
                        $lastSection = '';
                        foreach ($pages as $id => $page):
                            if ($page['section'] !== '' && $page['section'] !== $lastSection):
                                $lastSection = $page['section']; ?>
                                <span class="erp-nav__heading"><?= $this->e($lastSection) ?></span>
                            <?php endif; ?>
                            <a href="<?= url('/erp' . $page['route']) ?>"<?= $currentPageId === $id ? ' aria-current="page"' : '' ?>><?= $this->e($page['name']) ?></a>
                        <?php endforeach;
                    endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <div class="erp-sidebar__foot">
        <a class="erp-sidebar__profile" href="<?= url('/erp/configuracoes') ?>" title="Minha conta">
            <span class="erp-avatar" aria-hidden="true"><?= $this->e($initial) ?></span>
            <div><strong><?= $this->e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))) ?></strong><small>Connect · administradora</small></div>
        </a>
    </div>
</aside>
<div class="erp-backdrop" data-erp-nav-close></div>
