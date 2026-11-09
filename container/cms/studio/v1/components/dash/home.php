<?php $this->layout('layouts/studio'); ?>
<?php
/**
 * Dashboard do Studio no padrão do ERP: indicadores, acessos dos últimos dias, artigos recentes
 * e ações rápidas. Dados vêm de Studio::dash(); tudo opcional para a tela nunca quebrar vazia.
 */
$days = array_reverse(array_values((array)($accessDays ?? [])));
$totalViews = 0; $totalUsers = 0; $maxPages = 1;
foreach ($days as $day) {
    $totalViews += (int)($day->pages ?? 0);
    $totalUsers += (int)($day->users ?? 0);
    $maxPages = max($maxPages, (int)($day->pages ?? 0));
}
$recentPosts = array_slice(array_values((array)($recentPosts ?? [])), 0, 5);
$dashboardLogs = array_slice(array_values((array)($dashboardLogs ?? $logs ?? [])), 0, 5);
$fmt = static fn (int $n): string => number_format($n, 0, ',', '.');
$statusLabel = ['post' => ['Publicado', 'post'], 'draft' => ['Rascunho', 'draft'], 'trash' => ['Lixeira', 'trash']];
$kpis = [
    ['Páginas', $fmt((int)($pagesCount ?? 0)), 'conteúdos institucionais', 'documents-outline', '/studio/pages', 'pages.manage'],
    ['Artigos', $fmt((int)($postsCount ?? 0)), $fmt((int)($publishedCount ?? 0)) . ' publicados', 'newspaper-outline', '/studio/blog/home', 'articles.manage'],
    ['Usuários', $fmt((int)($usersCount ?? 0)), $fmt((int)($onlineCount ?? 0)) . ' online agora', 'people-outline', '/studio/users', 'users.manage'],
    ['Páginas vistas', $fmt($totalViews), $days ? 'nos últimos ' . count($days) . ' dias' : 'sem acessos registrados', 'eye-outline', '/studio/reports', 'reports.view'],
];
$actions = [
    ['Nova página', 'Conteúdo institucional', 'document-outline', '/studio/page', 'pages.manage'],
    ['Novo artigo', 'Publicar no blog', 'create-outline', '/studio/blog/post', 'articles.manage'],
    ['Novo destaque', 'Banner da página inicial', 'albums-outline', '/studio/slide', 'slides.manage'],
    ['Perguntas frequentes', 'Atualizar o FAQ', 'help-circle-outline', '/studio/faqs', 'faqs.manage'],
    ['Propostas recebidas', 'Acompanhar negociações', 'document-text-outline', '/studio/proposals', 'proposals.manage'],
    ['Configurações', 'Site, SEO e integrações', 'settings-outline', '/studio/settings', 'settings.manage'],
];
?>
<section class="studio-page-header studio-dashboard-head">
    <div><p class="studio-eyebrow">Visão geral · <?= date_fmt('now', 'd/m/Y') ?></p><h1 class="studio-page-title">Olá, <?= htmlspecialchars((string)($user->first_name ?? '')) ?>!</h1><p class="studio-page-description">Acompanhe o conteúdo e os acessos do site da Connect.</p></div>
    <div class="studio-page-actions"><a class="studio-btn outline" href="<?= function_exists('site_home_url') ? site_home_url() : url('/') ?>" target="_blank" rel="noopener"><ion-icon name="globe-outline"></ion-icon>Visualizar site</a><?php if ($user->can('articles.manage')): ?><a class="studio-btn primary" href="<?= url('/studio/blog/post') ?>"><ion-icon name="add-outline"></ion-icon>Novo artigo</a><?php endif; ?></div>
</section>

<section class="cx-kpis" aria-label="Indicadores">
    <?php foreach ($kpis as $i => [$label, $value, $meta, $icon, $href, $permission]): $tag = $user->can($permission) ? 'a' : 'div'; ?>
        <<?= $tag ?> class="cx-card cx-kpi<?= $i === 0 ? ' cx-kpi--hero' : '' ?>"<?= $tag === 'a' ? ' href="' . url($href) . '"' : '' ?>>
            <span class="cx-kpi__top"><span class="cx-kpi__label"><?= $label ?></span><span class="cx-kpi__icon" aria-hidden="true"><ion-icon name="<?= $icon ?>"></ion-icon></span></span>
            <strong class="cx-kpi__value"><?= $value ?></strong>
            <small class="cx-kpi__meta"><?= htmlspecialchars($meta) ?></small>
        </<?= $tag ?>>
    <?php endforeach; ?>
</section>

<section class="cx-grid">
    <article class="cx-card cx-col-8">
        <header class="cx-card__head"><div><h2>Acessos ao site</h2><p>Páginas vistas por dia<?= $totalUsers ? ' · ' . $fmt($totalUsers) . ' visitantes no período' : '' ?></p></div><?php if ($user->can('reports.view')): ?><a class="studio-btn ghost" href="<?= url('/studio/reports') ?>">Relatórios</a><?php endif; ?></header>
        <?php if (!$days): ?>
            <div class="cx-empty"><ion-icon name="stats-chart-outline"></ion-icon><p>Ainda não há acessos registrados.</p></div>
        <?php else: ?>
            <div class="cx-bars" role="img" aria-label="Páginas vistas nos últimos <?= count($days) ?> dias">
                <?php foreach ($days as $day): $pages = (int)($day->pages ?? 0); ?>
                    <div class="cx-bars__item" title="<?= $fmt($pages) ?> páginas vistas">
                        <span class="cx-bars__value"><?= $fmt($pages) ?></span>
                        <span class="cx-bars__bar" style="height: <?= max(4, (int)round($pages / $maxPages * 100)) ?>%"></span>
                        <span class="cx-bars__label"><?= !empty($day->created_at) ? date_fmt((string)$day->created_at, 'd/m') : '' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="cx-card cx-col-4">
        <header class="cx-card__head"><div><h2>Ações rápidas</h2><p>Atalhos do dia a dia</p></div></header>
        <nav class="cx-actions" aria-label="Ações rápidas">
            <?php foreach ($actions as [$label, $hint, $icon, $href, $permission]): if (!$user->can($permission)) continue; ?>
                <a href="<?= url($href) ?>"><span class="cx-actions__icon" aria-hidden="true"><ion-icon name="<?= $icon ?>"></ion-icon></span><span><strong><?= $label ?></strong><small><?= $hint ?></small></span><ion-icon class="cx-actions__go" name="chevron-forward-outline" aria-hidden="true"></ion-icon></a>
            <?php endforeach; ?>
        </nav>
    </article>

    <article class="cx-card cx-card--flush cx-col-<?= $dashboardLogs ? '8' : '12' ?>">
        <header class="cx-card__head"><div><h2>Artigos recentes</h2><p>Últimos conteúdos do blog</p></div><?php if ($user->can('articles.manage')): ?><a class="studio-btn ghost" href="<?= url('/studio/blog/home') ?>">Ver todos</a><?php endif; ?></header>
        <?php if (!$recentPosts): ?>
            <div class="cx-empty"><ion-icon name="newspaper-outline"></ion-icon><p>Nenhum artigo publicado ainda.</p><?php if ($user->can('articles.manage')): ?><a class="studio-btn primary" href="<?= url('/studio/blog/post') ?>">Escrever o primeiro</a><?php endif; ?></div>
        <?php else: ?>
            <div class="studio-table-wrap"><table class="studio-table"><thead><tr><th>Artigo</th><th>Status</th><th>Publicação</th></tr></thead><tbody>
                <?php foreach ($recentPosts as $post): [$statusText, $statusClass] = $statusLabel[$post->status ?? ''] ?? [(string)($post->status ?? ''), 'draft']; ?>
                    <tr><td><a href="<?= url('/studio/blog/post/' . (int)($post->id ?? 0)) ?>"><strong><?= htmlspecialchars((string)($post->title ?? 'Sem título')) ?></strong></a></td><td><span class="status <?= $statusClass ?>"><?= $statusText ?></span></td><td><?= !empty($post->post_at) ? date_fmt((string)$post->post_at, 'd/m/Y H:i') : '—' ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>

    <?php if ($dashboardLogs): ?>
    <article class="cx-card cx-col-4">
        <header class="cx-card__head"><div><h2>Atividade recente</h2><p>Registros do sistema</p></div><?php if ($user->can('logs.view')): ?><a class="studio-btn ghost" href="<?= url('/studio/system-logs') ?>">Ver log</a><?php endif; ?></header>
        <ul class="cx-feed">
            <?php foreach ($dashboardLogs as $item): ?>
                <li><span class="cx-feed__dot" aria-hidden="true"></span><span><strong><?= htmlspecialchars((string)($item->message ?? $item->title ?? 'Registro do sistema')) ?></strong><small><?= !empty($item->created_at) ? date_fmt((string)$item->created_at, 'd/m H:i') : '' ?></small></span></li>
            <?php endforeach; ?>
        </ul>
    </article>
    <?php endif; ?>
</section>
