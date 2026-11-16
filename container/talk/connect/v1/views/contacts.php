<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Contatos · Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'contacts'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Contatos</h1><p>Moradores e pessoas que já falaram com a Connect pelo WhatsApp.</p></div>
</header>
<main class="contacts">
  <section class="card c-list">
    <header class="c-filters">
      <input id="c-q" type="search" placeholder="Buscar por nome, telefone, condomínio ou unidade" aria-label="Buscar contatos">
      <select id="c-tag" aria-label="Etiqueta"><option value="">Todas as etiquetas</option></select>
      <select id="c-status" aria-label="Situação"><option value="">Todos</option><option value="erp">Identificados no ERP</option><option value="unknown">Não identificados</option><option value="blocked">Bloqueados</option></select>
    </header>
    <div class="q-scroll"><table>
      <thead><tr><th>Contato</th><th>Condomínio / unidade</th><th>Etiquetas</th><th>Atendimentos</th><th>Último contato</th></tr></thead>
      <tbody id="c-rows"><tr><td colspan="5" class="meta">Carregando…</td></tr></tbody>
    </table></div>
    <footer class="c-foot"><span id="c-count" class="meta"></span><span class="pager"><button type="button" id="c-prev">‹</button><b id="c-page">1</b><button type="button" id="c-next">›</button></span></footer>
  </section>
  <aside class="card c-detail" id="c-detail"><p class="meta">Escolha um contato para ver os detalhes.</p></aside>
</main>
</div>
</div>
<dialog id="block-dialog">
  <form method="dialog">
    <h3>Bloquear contato</h3>
    <p class="meta">As mensagens desta pessoa deixam de entrar no Connect Talk (ficam só contadas) e os atendimentos abertos são encerrados sem aviso a ela. Dá para desbloquear depois.</p>
    <label>Motivo (opcional)<input id="block-reason" maxlength="200" placeholder="Ex.: mensagens ofensivas, propaganda"></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary danger">Bloquear</button></menu>
  </form>
</dialog>
<script src="/container/talk/connect/v1/assets/contacts.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
