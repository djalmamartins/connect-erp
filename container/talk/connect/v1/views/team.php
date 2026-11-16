<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Equipe e setores · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'team'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Equipe e setores</h1><p>Quem atende, em quais setores e com qual papel.</p></div>
  <button type="button" class="primary" id="t-new">+ Novo atendente</button>
</header>
<nav class="t-tabs" role="tablist">
  <button type="button" role="tab" data-tab="users" aria-selected="true">Atendentes <b id="t-users-n"></b></button>
  <button type="button" role="tab" data-tab="sectors" aria-selected="false">Setores <b id="t-sectors-n"></b></button>
  <button type="button" role="tab" data-tab="audit" aria-selected="false">Registro de alterações</button>
</nav>
<main class="team">
  <section class="card" data-panel="users">
    <div class="t-filters">
      <input id="t-q" type="search" placeholder="Buscar por nome ou e-mail" aria-label="Buscar atendentes">
      <select id="t-sector" aria-label="Setor"><option value="">Todos os setores</option></select>
      <select id="t-status" aria-label="Situação"><option value="active">Ativos</option><option value="inactive">Desativados</option><option value="">Todos</option></select>
    </div>
    <div class="q-scroll"><table>
      <thead><tr><th>Atendente</th><th>Papel</th><th>Setores</th><th>Situação</th><th>Em atendimento</th><th>Encerrados hoje</th><th></th></tr></thead>
      <tbody id="t-rows"><tr><td colspan="7" class="meta">Carregando…</td></tr></tbody>
    </table></div>
    <dl class="t-roles">
      <dt>Atendente</dt><dd>atende as conversas dos setores marcados.</dd>
      <dt>Gerente</dt><dd>atende e acompanha tudo dos setores marcados; cuida das mensagens rápidas do setor.</dd>
      <dt>Supervisor</dt><dd>vê todos os setores, cadastra a equipe e as conexões do WhatsApp.</dd>
    </dl>
  </section>
  <section class="t-sectors" data-panel="sectors" hidden>
    <div id="s-cards" class="s-grid"></div>
  </section>
  <section class="card" data-panel="audit" hidden>
    <ul id="a-rows" class="a-list"><li class="meta">Carregando…</li></ul>
  </section>
</main>
</div>
</div>

<dialog id="u-dialog">
  <form method="dialog" class="u-form" id="u-form">
    <h3 id="u-title">Novo atendente</h3>
    <div class="ch-grid">
      <label>Nome<input name="name" maxlength="100" required></label>
      <label>E-mail (login)<input name="email" type="email" maxlength="150" required autocomplete="off"></label>
      <label><span id="u-pw-label">Senha inicial</span><input name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password"></label>
      <label>Usuário no ERP (opcional)<input name="erp_user_id" inputmode="numeric" placeholder="nº do usuário no Connect ERP"></label>
    </div>
    <fieldset class="u-roles">
      <legend>Papel</legend>
      <label><input type="radio" name="role" value="agent" checked><b>Atendente</b></label>
      <label><input type="radio" name="role" value="manager"><b>Gerente</b></label>
      <label><input type="radio" name="role" value="supervisor"><b>Supervisor</b></label>
    </fieldset>
    <fieldset class="u-sectors" id="u-sectors"><legend>Setores</legend></fieldset>
    <p class="meta" id="u-pw-hint">Pelo menos 8 caracteres, com letras e números. Passe a senha à pessoa por um canal seguro; ela pode trocar depois em "Trocar senha".</p>
    <p class="form-error" id="u-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Salvar</button></menu>
  </form>
</dialog>

<dialog id="s-dialog">
  <form method="dialog" id="s-form">
    <h3 id="s-title">Novo setor</h3>
    <label>Nome<input name="name" maxlength="80" required></label>
    <label>Descrição (opcional)<input name="description" maxlength="255" placeholder="Ex.: boletos, acordos e 2ª via"></label>
    <label>Cor<input name="color" type="color" value="#12804A"></label>
    <p class="meta" id="s-jack" hidden>A Jack encaminha para este setor. Pode renomear sem problema: ela continua reconhecendo.</p>
    <p class="form-error" id="s-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Salvar</button></menu>
  </form>
</dialog>
<script src="/container/talk/connect/v1/assets/team.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
