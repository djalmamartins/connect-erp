<?php $this->layout('layouts/studio'); ?>
<?php
$totalViews = 0;
foreach ($accessDays as $day) { $totalViews += (int)$day->pages; }
?>
<section class="studio-page-header studio-dashboard-head">
    <div><p class="studio-eyebrow">Visão geral</p><h1 class="studio-page-title">Olá, <?= htmlspecialchars($user->first_name) ?>!</h1><p class="studio-page-description">Aqui está o resumo do seu site hoje.</p></div>
    <div class="studio-page-actions"><a class="studio-btn outline" href="<?= url('/') ?>" target="_blank"><ion-icon name="globe-outline"></ion-icon>Visualizar site</a></div>
</section>
<section class="studio-dashboard-kpis">
    <a class="studio-panel" href="<?= url('/studio/pages') ?>"><i><ion-icon name="documents-outline"></ion-icon></i><div><span>Páginas</span><strong><?= $pagesCount ?></strong><small>conteúdos cadastrados</small></div></a>
    <a class="studio-panel" href="<?= url('/studio/blog/home') ?>"><i><ion-icon name="newspaper-outline"></ion-icon></i><div><span>Artigos</span><strong><?= $postsCount ?></strong><small><?= $publishedCount ?> publicados</small></div></a>
    <a class="studio-panel" href="<?= url('/studio/users') ?>"><i><ion-icon name="people-outline"></ion-icon></i><div><span>Usuários</span><strong><?= $usersCount ?></strong><small><?= $onlineCount ?> online agora</small></div></a>
    <a class="studio-panel" href="<?= url('/studio/reports') ?>"><i><ion-icon name="eye-outline"></ion-icon></i><div><span>Visualizações</span><strong><?= number_format($totalViews, 0, ',', '.') ?></strong><small>nos últimos registros</small></div></a>
    <?php if($user->can('proposals.manage')):?><a class="studio-panel" href="<?= url('/studio/proposals') ?>"><i><ion-icon name="document-text-outline"></ion-icon></i><div><span>Propostas</span><strong>Ver lista</strong><small>acompanhe negociações</small></div></a><?php endif;?>
</section>

<?php
$dashboardLogs = $dashboardLogs ?? $logs ?? [];
$dashboardProposals = $dashboardProposals ?? $newProposals ?? $proposals ?? [];
?>
<section class="studio-dashboard-feed">
<?php if(!empty($dashboardLogs)): ?><section class="studio-panel studio-dashboard-feed-card"><header class="studio-panel-header"><div><p class="studio-eyebrow">Sistema</p><h2 class="studio-panel-title">Logs recentes</h2></div><a class="studio-btn ghost" href="<?= url('/studio/system-logs') ?>">Ver todos</a></header><div class="studio-panel-body studio-dashboard-list"><?php foreach(array_slice((array)$dashboardLogs,0,5) as $item): ?><a href="<?= url('/studio/system-logs') ?>"><ion-icon name="pulse-outline"></ion-icon><span><strong><?= htmlspecialchars($item->message ?? $item->title ?? 'Registro do sistema') ?></strong><small><?= !empty($item->created_at)?date_fmt($item->created_at,'d/m H:i'):'' ?></small></span></a><?php endforeach; ?></div></section><?php endif; ?>
<?php if(!empty($dashboardProposals) && $user->can('proposals.manage')): ?><section class="studio-panel studio-dashboard-feed-card"><header class="studio-panel-header"><div><p class="studio-eyebrow">Comercial</p><h2 class="studio-panel-title">Novas propostas</h2></div><a class="studio-btn ghost" href="<?= url('/studio/proposals') ?>">Ver todas</a></header><div class="studio-panel-body studio-dashboard-list"><?php foreach(array_slice((array)$dashboardProposals,0,5) as $item): ?><a href="<?= url('/studio/proposals/' . ($item->id ?? '')) ?>"><ion-icon name="document-text-outline"></ion-icon><span><strong><?= htmlspecialchars($item->name ?? $item->title ?? 'Nova proposta') ?></strong><small><?= htmlspecialchars($item->condominium ?? $item->protocol ?? '') ?></small></span></a><?php endforeach; ?></div></section><?php endif; ?>
</section>

<section class="studio-dashboard-actions studio-panel-body">
    <p class="studio-eyebrow">Ações rápidas</p>
    <div class="studio-actions"><a class="studio-btn primary" href="<?= url('/studio/page') ?>">Nova página</a>
    <a class="studio-btn" href="<?= url('/studio/blog/post') ?>">Novo artigo</a>
    <a class="studio-btn" href="<?= url('/studio/slide') ?>">Novo destaque</a>
    <a class="studio-btn" href="<?= url('/studio/faqs') ?>">Perguntas frequentes</a>
    <?php if($user->can('proposals.manage')):?><a class="studio-btn" href="<?= url('/studio/proposals') ?>">Propostas recebidas</a><?php endif;?>
    <a class="studio-btn" href="<?= url('/studio/settings') ?>">Configurações</a></div>
</section>
