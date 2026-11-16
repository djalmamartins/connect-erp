<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Horário de atendimento · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'settings'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Horário de atendimento</h1><p>Quando a equipe atende e o que o cliente recebe quando escreve fora desse horário.</p></div>
  <div class="hr-head-actions">
    <label class="switch"><input type="checkbox" id="hr-enabled"><span></span><b id="hr-enabled-label">Desligado</b></label>
    <button type="button" class="primary" id="hr-save">Salvar</button>
  </div>
</header>
<main class="hours">
  <p class="form-error" id="hr-error" role="alert" hidden></p>
  <section class="card hr-now"><h3>Agora <small class="meta" id="hr-clock"></small></h3><ul id="hr-status"></ul></section>
  <div class="hr-cols">
    <div class="hr-col">
      <section class="card"><h3>Horário geral <button type="button" class="link" id="hr-copy">Copiar segunda para os dias úteis</button></h3>
        <p class="meta">Até 3 faixas por dia (ex.: 8h às 12h e 13h às 18h). Dia sem faixa: fechado.</p>
        <div class="hr-week" id="hr-week"></div></section>
      <section class="card"><h3>Setores</h3>
        <p class="meta">Cada setor segue o horário geral, tem horário próprio ou atende 24 horas (ex.: plantão da Manutenção).</p>
        <div id="hr-sectors"></div></section>
    </div>
    <div class="hr-col">
      <section class="card"><h3>Mensagem fora do horário</h3>
        <p class="meta">Vai uma vez por atendimento, no lugar do "já vamos te atender" da Jack. O atendimento fica na fila do setor.</p>
        <textarea id="hr-message" rows="6" maxlength="1000"></textarea>
        <div class="hr-vars" id="hr-vars"></div>
        <div class="hr-preview"><small>Como o cliente recebe</small><div class="wa-bubble" id="hr-preview"></div></div></section>
      <section class="card"><h3>Feriados <button type="button" class="link" id="hr-import"></button></h3>
        <p class="meta">No feriado, só os setores de 24 horas atendem.</p>
        <form class="hr-add" id="hr-add"><input type="date" name="day" required aria-label="Data"><input name="name" maxlength="80" placeholder="Nome (ex.: Aniversário da cidade)" required aria-label="Nome do feriado"><button class="ghost">Adicionar</button></form>
        <ul class="hr-holidays" id="hr-holidays"></ul></section>
    </div>
  </div>
</main>
</div>
</div>
<script src="/container/talk/connect/v1/assets/settings.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
