<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Painel · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'reports'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Painel</h1><p id="rp-sub">Como andam os atendimentos: volume, tempo de espera, tempo de atendimento e avaliação dos clientes.</p></div>
  <a class="ghost btn rp-export" id="rp-export" href="#" download>Exportar planilha</a>
</header>
<form class="rp-filters" id="rp-filters" aria-label="Filtros">
  <div class="rp-periods" role="group" aria-label="Período">
    <button type="button" data-p="today">Hoje</button><button type="button" data-p="7" class="on">7 dias</button><button type="button" data-p="30">30 dias</button><button type="button" data-p="month">Este mês</button>
  </div>
  <label>De <input type="date" name="from" id="rp-from"></label>
  <label>até <input type="date" name="to" id="rp-to"></label>
  <select name="sector_id" id="rp-sector" aria-label="Setor" hidden><option value="">Todos os setores</option></select>
  <select name="user_id" id="rp-user" aria-label="Atendente" hidden><option value="">Todos os atendentes</option></select>
</form>
<main class="reports">
  <p class="form-error" id="rp-error" role="alert" hidden></p>
  <section class="card"><div class="kpis rp-kpis" id="rp-kpis"></div></section>
  <div class="rp-row">
    <section class="card rp-chart"><h3>Atendimentos por dia <small class="meta" id="rp-day-note"></small></h3><div class="bars" id="rp-days"></div></section>
    <section class="card rp-chart"><h3>Por hora do dia <small class="meta">quando os clientes mais escrevem</small></h3><div class="bars hours" id="rp-hours"></div></section>
  </div>
  <section class="card"><h3>Por setor</h3><div class="q-scroll"><table class="rp-table"><thead><tr><th>Setor</th><th class="r">Atendimentos</th><th class="r">Encerrados</th><th class="r">Espera média</th><th class="r">Atendimento médio</th><th class="r">Nota</th></tr></thead><tbody id="rp-sectors"></tbody></table></div></section>
  <section class="card"><h3>Por atendente</h3><div class="q-scroll"><table class="rp-table"><thead><tr><th>Atendente</th><th class="r">Atendimentos</th><th class="r">Encerrados</th><th class="r">Espera média</th><th class="r">Atendimento médio</th><th class="r">Nota</th></tr></thead><tbody id="rp-users"></tbody></table></div></section>
  <div class="rp-row">
    <section class="card"><h3>Notas dos clientes <small class="meta" id="rp-rate-note"></small></h3><div class="rp-dist" id="rp-dist"></div></section>
    <section class="card"><h3>Últimas avaliações</h3><ul class="rp-comments" id="rp-comments"></ul></section>
  </div>
</main>
</div>
</div>
<script src="/container/talk/connect/v1/assets/reports.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
