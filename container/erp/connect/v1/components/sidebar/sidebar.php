<?php
/**
 * Sidebar do ERP: grupos do catálogo em <details>, item ativo e modo recolhido.
 * Grupos longos (mais de 14 páginas) mostram cada seção como submenu recolhível com
 * todas as suas páginas, para o menu caber na tela sem esconder nenhuma página.
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
        <a class="erp-brand" href="<?= erp_url('/dashboard') ?>" aria-label="Connect ERP — início">
            <img class="erp-brand__logo" src="<?= $this->e(function_exists('site_logo_url') ? site_logo_url() : theme('/assets/images/logo-connect-condominios.svg')) ?>" alt="Connect Condomínios" width="152" height="40">
            <img class="erp-brand__mark" src="<?= theme('/assets/images/marca_connect.svg') ?>" alt="" width="34" height="34">
        </a>
        <button type="button" class="erp-sidebar__collapse" data-erp-collapse aria-label="Recolher menu" aria-controls="erp-sidebar" aria-expanded="true"><?= $icon('chevron-left') ?></button>
        <button type="button" class="erp-sidebar__close" data-erp-nav-close aria-label="Fechar menu"><?= $icon('close') ?></button>
    </div>
    <?php
    // Alternador de ambientes (ADR 0034): mesmo catálogo do Studio e do Help Desk.
    $environmentIcons = ['studio' => 'sparkle', 'erp' => 'building', 'helpdesk' => 'help', 'residents' => 'home', 'site' => 'eye'];
    $environments = \Source\Services\Environments::menu($user, 'erp');
    ?>
    <details class="erp-sidebar__context erp-envswitch" data-erp-dropdown>
        <summary title="Trocar de ambiente"><span><small>ERP</small><strong>Connect ERP</strong></span><?= $icon('chevron-right', 'erp-icon--sm erp-envswitch__chevron') ?></summary>
        <nav class="erp-envswitch__panel" aria-label="Ambientes Connect">
            <small class="erp-envswitch__title">Ambientes Connect</small>
            <?php foreach ($environments as $environment): ?>
                <a href="<?= $this->e($environment['href']) ?>"<?= $environment['current'] ? ' aria-current="page"' : '' ?>><?= $icon($environmentIcons[$environment['key']] ?? 'info', 'erp-icon--sm') ?><span><strong><?= $this->e($environment['label']) ?></strong><small><?= $this->e($environment['description']) ?></small></span><?= $environment['current'] ? '<em>Atual</em>' : '' ?></a>
            <?php endforeach; ?>
        </nav>
    </details>
    <nav class="erp-nav" aria-label="Menu principal">
        <?php foreach ($catalog['groups'] as $groupKey => $group):
            $pages = $pagesByGroup[$groupKey] ?? [];
            $isActive = $currentGroup === $groupKey;
            if ($groupKey === 'dashboard'): ?>
                <a class="erp-nav__link" href="<?= erp_url('/dashboard') ?>"<?= $currentPageId === 'AP-001' ? ' aria-current="page"' : '' ?> title="Dashboard"><?= $icon($group['icon']) ?><span class="erp-nav__label">Dashboard</span></a>
                <?php continue;
            endif;
            $long = count($pages) > 14;
            $hubHref = $group['prefix'];
            $hasHub = !in_array($group['prefix'], array_column($catalog['pages'], 'route'), true); ?>
            <details class="erp-nav__group" data-erp-group="<?= $this->e($groupKey) ?>"<?= $isActive ? ' open' : '' ?>>
                <summary<?= $isActive ? ' class="is-active"' : '' ?> title="<?= $this->e($group['label']) ?>"><?= $icon($group['icon']) ?><span class="erp-nav__label"><?= $this->e($group['label']) ?></span></summary>
                <div class="erp-nav__sub">
                    <?php if ($hasHub): ?>
                        <a href="<?= erp_url($hubHref) ?>"<?= $isActive && $currentPageId === null ? ' aria-current="page"' : '' ?>>Visão geral</a>
                    <?php endif; ?>
                    <?php if ($long):
                        // Grupos longos: cada seção vira um submenu recolhível com todas as páginas dela,
                        // para nenhuma página ficar escondida atrás da visão geral (teste do Djalma, 09/11).
                        $bySection = [];
                        foreach ($pages as $id => $page) {
                            $bySection[$page['section'] !== '' ? $page['section'] : 'Outras'][$id] = $page;
                        }
                        foreach ($bySection as $section => $sectionPages):
                            $sectionActive = $isActive && $currentSection === $section; ?>
                            <details class="erp-nav__section"<?= $sectionActive ? ' open' : '' ?>>
                                <summary><?= $this->e($section) ?></summary>
                                <?php foreach ($sectionPages as $id => $page): ?>
                                    <a href="<?= erp_url($page['route']) ?>"<?= $currentPageId === $id ? ' aria-current="page"' : '' ?>><?= $this->e($page['name']) ?></a>
                                <?php endforeach; ?>
                            </details>
                        <?php endforeach;
                    else:
                        $lastSection = '';
                        foreach ($pages as $id => $page):
                            if ($page['section'] !== '' && $page['section'] !== $lastSection):
                                $lastSection = $page['section']; ?>
                                <span class="erp-nav__heading"><?= $this->e($lastSection) ?></span>
                            <?php endif; ?>
                            <a href="<?= erp_url($page['route']) ?>"<?= $currentPageId === $id ? ' aria-current="page"' : '' ?>><?= $this->e($page['name']) ?></a>
                        <?php endforeach;
                    endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <div class="erp-sidebar__foot">
        <a class="erp-sidebar__profile" href="<?= erp_url('/configuracoes') ?>" title="Minha conta">
            <span class="erp-avatar" aria-hidden="true"><?= $this->e($initial) ?></span>
            <div><strong><?= $this->e(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))) ?></strong><small>Connect · administradora</small></div>
        </a>
    </div>
</aside>
<div class="erp-backdrop" data-erp-nav-close></div>
