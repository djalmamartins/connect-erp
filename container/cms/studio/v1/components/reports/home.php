<?php $this->layout('layouts/studio'); ?>
<?php
$days = array_reverse($access);
$totalUsers = $totalViews = $totalPages = 0;
foreach ($days as $day) {
    $totalUsers += (int)$day->users;
    $totalViews += (int)$day->views;
    $totalPages += (int)$day->pages;
}
$averagePages = $totalViews > 0 ? $totalPages / $totalViews : 0;
$maxPages = max(array_map(static fn($day) => (int)$day->pages, $days) ?: [1]);
$firstHalf = array_slice($days, 0, (int)floor(count($days) / 2));
$secondHalf = array_slice($days, (int)floor(count($days) / 2));
$sumPages = static fn(array $items): int => array_sum(array_map(static fn($day) => (int)$day->pages, $items));
$previousPages = $sumPages($firstHalf);
$currentPages = $sumPages($secondHalf);
$variation = $previousPages > 0 ? (($currentPages - $previousPages) / $previousPages) * 100 : ($currentPages > 0 ? 100 : 0);
?>
<section class="studio-page-header studio-report-head">
    <div><p class="studio-eyebrow">Análise</p><h1 class="studio-page-title">Relatórios de acesso</h1><p class="studio-page-description">Acompanhe o desempenho do site e as pessoas conectadas nos últimos 30 registros.</p></div>
    <div class="studio-page-actions studio-report-head-actions"><span class="studio-badge success"><i></i><?= count($online) ?> online agora</span><a class="studio-btn" href="<?= url('/') ?>" target="_blank"><ion-icon name="open-outline"></ion-icon>Ver site</a></div>
</section>
<section class="studio-report-kpis studio-settings-summary">
    <article><i><ion-icon name="people-outline"></ion-icon></i><div><span>Visitantes</span><strong><?= number_format($totalUsers,0,',','.') ?></strong><small>Pessoas no período</small></div></article>
    <article><i><ion-icon name="compass-outline"></ion-icon></i><div><span>Sessões</span><strong><?= number_format($totalViews,0,',','.') ?></strong><small>Visitas registradas</small></div></article>
    <article><i><ion-icon name="eye-outline"></ion-icon></i><div><span>Visualizações</span><strong><?= number_format($totalPages,0,',','.') ?></strong><small><?= number_format($averagePages,1,',','.') ?> páginas por sessão</small></div></article>
    <article><i><ion-icon name="trending-<?= $variation < 0 ? 'down' : 'up' ?>-outline"></ion-icon></i><div><span>Evolução</span><strong><?= ($variation > 0 ? '+' : '') . number_format($variation,1,',','.') ?>%</strong><small>Comparação entre metades</small></div></article>
</section>
<div class="studio-report-grid">
    <section class="studio-panel studio-report-traffic">
        <header><div><small>TRÁFEGO</small><h2>Visualizações por dia</h2><p>Evolução das páginas acessadas no período.</p></div><span>30 registros</span></header>
        <div class="studio-report-chart" aria-label="Gráfico de visualizações diárias">
            <?php if (!$days): ?><div class="studio-empty">Ainda não há dados de acesso.</div><?php endif; ?>
            <?php foreach ($days as $day): $height=max(4,((int)$day->pages/$maxPages)*100); ?>
                <div title="<?= date_fmt($day->created_at,'d/m/Y') ?>: <?= (int)$day->pages ?> visualizações"><b><?= (int)$day->pages ?></b><span style="height:<?= $height ?>%"></span><small><?= date_fmt($day->created_at,'d/m') ?></small></div>
            <?php endforeach; ?>
        </div>
        <footer><div><span>Maior volume</span><strong><?= number_format($maxPages,0,',','.') ?></strong></div><div><span>Média diária</span><strong><?= number_format(count($days) ? $totalPages/count($days) : 0,1,',','.') ?></strong></div><div><span>Total do período</span><strong><?= number_format($totalPages,0,',','.') ?></strong></div></footer>
    </section>
    <section class="studio-panel studio-report-online">
        <header><div><small>TEMPO REAL</small><h2>Visitantes online</h2><p>Atividade dos últimos 10 minutos.</p></div><span><?= count($online) ?></span></header>
        <div>
            <?php if (!$online): ?><div class="studio-empty">Nenhum visitante online agora.</div><?php endif; ?>
            <?php foreach (array_slice($online,0,8) as $session): $onlineUser=$session->user(); ?>
                <article><i><ion-icon name="<?= $onlineUser?'person-outline':'globe-outline' ?>"></ion-icon></i><div><strong><?= $onlineUser?htmlspecialchars($onlineUser->fullName()):'Visitante' ?></strong><small><?= htmlspecialchars((string)($session->url ?: '/')) ?></small></div><time><?= date_fmt($session->updated_at,'H:i') ?></time></article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
