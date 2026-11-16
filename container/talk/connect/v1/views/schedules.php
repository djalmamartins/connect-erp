<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Agendamentos · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'schedules'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Agendamentos</h1><p>Mensagens marcadas para sair num dia e hora. O Talk manda sozinho, pelo número de QR Code.</p></div>
  <button type="button" class="primary" id="sc-new">+ Novo agendamento</button>
</header>
<nav class="t-tabs" role="tablist">
  <button type="button" role="tab" data-tab="scheduled" aria-selected="true">Próximos <b id="n-scheduled"></b></button>
  <button type="button" role="tab" data-tab="sent" aria-selected="false">Enviados <b id="n-sent"></b></button>
  <button type="button" role="tab" data-tab="canceled" aria-selected="false">Cancelados <b id="n-canceled"></b></button>
  <button type="button" role="tab" data-tab="failed" aria-selected="false">Com falha <b id="n-failed" class="warn"></b></button>
</nav>
<main class="schedules" id="sc-list"><p class="meta">Carregando…</p></main>
</div>
</div>

<dialog id="sc-dialog">
  <form method="dialog" class="sc-form" id="sc-form">
    <h3 id="sc-title">Novo agendamento</h3>
    <div id="sc-to-conv" class="sc-to" hidden></div>
    <div id="sc-to-pick">
      <label>Para (nome, telefone ou condomínio)
        <input id="sc-contact" autocomplete="off" placeholder="Busque um contato ou digite um telefone com DDD">
      </label>
      <ul id="sc-suggest" class="sc-suggest" hidden></ul>
      <div id="sc-chosen" class="sc-to" hidden></div>
      <label id="sc-newname" hidden>Nome do contato (opcional)<input name="name" maxlength="150"></label>
      <label>Setor<select name="sector_id" id="sc-sector"></select></label>
    </div>
    <div class="ch-grid">
      <label>Dia<input type="date" name="date" required></label>
      <label>Hora<input type="time" name="time" required step="300"></label>
    </div>
    <div class="sc-quick" id="sc-quick"></div>
    <label>Mensagem<textarea name="body" rows="5" maxlength="4096" required placeholder="Ex.: Bom dia! Passando para lembrar da assembleia de amanhã às 19h no salão de festas."></textarea></label>
    <div class="sc-foot"><label class="check"><input type="checkbox" name="sign" checked> Assinar com meu nome</label><small id="sc-count" class="meta">0/4096</small></div>
    <p class="form-error" id="sc-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary" id="sc-save">Agendar</button></menu>
  </form>
</dialog>
<script src="/container/talk/connect/v1/assets/schedules.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
