<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Fila de atendimento · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'queue'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Fila de atendimento</h1><p>Acompanhe e gerencie a fila de espera dos atendimentos.</p></div>
  <input id="q-search" class="q-search" type="search" placeholder="Buscar na fila…" aria-label="Buscar na fila">
</header>
<main class="queue">
  <div class="q-main">
    <section class="card q-overview">
      <header><div><h2>Visão geral da fila</h2><small id="q-updated">Atualizando…</small></div>
        <button type="button" id="q-refresh" class="ghost"><svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 10-2.3 5.7M20 4v7h-7"/></svg>Atualizar fila</button></header>
      <div class="kpis">
        <div class="kpi"><span class="ic g"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M16 5a3 3 0 010 6M18 14c2 .5 3 2.4 3 5"/></svg></span>
          <div><b id="k-waiting">–</b><span>Em espera</span><small>Aguardando atendimento</small></div></div>
        <div class="kpi"><span class="ic y"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg></span>
          <div><b id="k-avg">–</b><span>Tempo médio de espera</span><small>De quem está na fila agora</small></div></div>
        <div class="kpi"><span class="ic b"><svg viewBox="0 0 24 24"><path d="M4 5h16v10H8l-4 4z"/></svg></span>
          <div><b id="k-today">–</b><span>Atendimentos hoje</span><small>Conversas que entraram hoje</small></div></div>
        <div class="kpi"><span class="ic p"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg></span>
          <div><b id="k-rate">–</b><span>Taxa de resolução</span><small id="k-rate-sub">Encerradas das de hoje</small></div></div>
      </div>
    </section>
    <section class="card q-table">
      <header><nav class="q-tabs" id="q-tabs"></nav>
        <label class="q-order">Ordenar por: <select id="q-order"><option value="oldest">Mais antigo</option><option value="priority">Prioridade</option><option value="newest">Mais recente</option></select></label></header>
      <div class="q-scroll"><table>
        <thead><tr><th>Posição</th><th>Contato</th><th>Setor</th><th>Assunto</th><th>Tempo de espera</th><th class="r">Ações</th></tr></thead>
        <tbody id="q-rows"><tr><td colspan="6" class="meta">Carregando…</td></tr></tbody>
      </table></div>
      <footer id="q-foot" class="meta"></footer>
    </section>
  </div>
  <aside class="q-side">
    <section class="card"><h3>Distribuição da fila</h3><div id="q-donut" class="donut-wrap"></div><ul id="q-dist" class="q-dist"></ul></section>
    <section class="card"><h3>Atendentes <span id="q-online" class="pill"></span></h3><ul id="q-people" class="q-people"></ul></section>
    <section class="card"><h3>Tempo de espera</h3><dl class="q-waits">
      <dt>Maior tempo de espera</dt><dd id="w-max" class="late">–</dd>
      <dt>Tempo médio geral</dt><dd id="w-avg" class="warn">–</dd>
      <dt>Menor tempo de espera</dt><dd id="w-min" class="ok">–</dd></dl></section>
    <section class="card tip"><b>Dica</b><p>Atenda primeiro quem espera há mais tempo. Acima de <span id="t-warn">15</span> min o tempo fica amarelo; acima de <span id="t-late">30</span> min, vermelho.</p></section>
  </aside>
</main>
</div>
</div>
<menu id="q-menu" class="q-menu" hidden>
  <li><button type="button" data-prio="high">Prioridade alta</button></li>
  <li><button type="button" data-prio="normal">Prioridade normal</button></li>
  <li><button type="button" data-prio="low">Prioridade baixa</button></li>
  <li><button type="button" data-open>Abrir conversa</button></li>
</menu>
<script src="/container/talk/connect/v1/assets/queue.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
