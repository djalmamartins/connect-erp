<?php defined('CONF_DB_NAME') || exit; ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Connect Talk</title>
<link rel="stylesheet" href="/container/talk/connect/v1/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'inbox'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Atendimentos</h1><p>Acompanhe a fila e responda os clientes pelo WhatsApp.</p></div>
</header>
<main class="layout">
  <aside class="list">
    <nav class="tabs" id="tabs">
      <button data-tab="pending" class="active">Fila <b id="badge-pending"></b></button>
      <button data-tab="mine">Meus</button>
      <button data-tab="open">Em atendimento</button>
      <button data-tab="closed">Encerrados</button>
    </nav>
    <div class="filters">
      <div class="filter-row"><select id="sector-filter"><option value="">Todos os setores</option></select>
      <select id="tag-filter"><option value="">Todas as etiquetas</option></select></div>
      <p class="search-on" id="search-on" hidden></p>
    </div>
    <ul id="conversations" class="items"></ul>
  </aside>

  <section class="chat" id="chat">
    <div class="empty">Selecione uma conversa</div>
  </section>

  <aside class="side" id="side">
    <div id="contact-panel"></div>
  </aside>
</main>
</div>
</div>

<dialog id="transfer-dialog">
  <form method="dialog" id="transfer-form" class="xfer">
    <header><h3><svg viewBox="0 0 24 24"><path d="M4 8h13l-3-3M20 16H7l3 3"/></svg>Trocar atendente</h3>
      <p>Transfira este atendimento para outro setor ou atendente. O cliente será avisado da transferência.</p></header>
    <fieldset>
      <legend>Para onde deseja transferir?</legend>
      <div class="xfer-row"><label class="radio"><input type="radio" name="xfer-mode" value="sector" checked> Transferir para um setor</label>
        <select id="transfer-sector"></select></div>
      <div class="xfer-row"><label class="radio"><input type="radio" name="xfer-mode" value="user"> Transferir para um atendente</label>
        <select id="transfer-user" disabled><option value="">Selecione um atendente</option></select></div>
    </fieldset>
    <label class="xfer-note"><b>Relate o motivo da transferência</b>
      <span>Descreva o motivo e as informações importantes para o próximo atendente.</span>
      <textarea id="transfer-note" rows="4" maxlength="500" placeholder="Ex.: morador relata vazamento no teto da garagem, bloco A"></textarea>
      <small id="transfer-count">0/500</small></label>
    <label class="check"><input type="checkbox" id="transfer-history" checked> Manter histórico visível para o próximo atendente
      <span>O novo atendente poderá ver todo o histórico da conversa.</span></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Transferir atendimento</button></menu>
  </form>
</dialog>
<dialog id="quick-dialog">
  <div class="quick">
    <header><h3>Mensagens rápidas</h3>
      <p>No campo de resposta, digite <b>/</b> e o atalho para inserir. Campos trocados na hora:
        <code>{primeiro_nome}</code> <code>{nome}</code> <code>{protocolo}</code> <code>{setor}</code> <code>{atendente}</code>.</p></header>
    <ul id="quick-list" class="quick-list"></ul>
    <form id="quick-form" class="quick-form" hidden>
      <input type="hidden" id="quick-id">
      <div class="quick-row"><label>Atalho<input id="quick-shortcut" required maxlength="40" placeholder="bomdia"></label>
        <label>Setor<select id="quick-sector"></select></label></div>
      <label>Mensagem<textarea id="quick-body" rows="4" required maxlength="2000" placeholder="Bom dia, {primeiro_nome}! Em que posso ajudar?"></textarea></label>
      <menu><button type="button" id="quick-cancel">Cancelar</button><button class="primary">Salvar</button></menu>
    </form>
    <menu id="quick-actions"><button type="button" id="quick-new" class="primary">Nova mensagem</button><button type="button" id="quick-close">Fechar</button></menu>
  </div>
</dialog>
<dialog id="tags-dialog">
  <div class="quick">
    <header><h3>Etiquetas</h3><p>Marque os contatos para achar e organizar depois (ex.: Síndico, Inadimplente, Obra). A etiqueta fica no contato e aparece em todas as conversas dele.</p></header>
    <ul id="tags-list" class="quick-list tags-list"></ul>
    <form id="tag-form" class="quick-form">
      <input type="hidden" id="tag-id">
      <div class="quick-row"><label>Nome<input id="tag-name" required maxlength="40" placeholder="Síndico"></label>
        <div><span class="lbl">Cor</span><div id="tag-colors" class="tag-colors"></div></div></div>
      <menu><button type="button" id="tags-close">Fechar</button><button class="primary" id="tag-save">Criar etiqueta</button></menu>
    </form>
  </div>
</dialog>
<dialog id="ticket-dialog">
  <form method="dialog" id="ticket-form">
    <h3>Abrir chamado no Help Desk</h3>
    <p class="meta">O chamado cai no Help Desk do Connect ERP, na área do setor desta conversa, com o nome e o telefone do cliente.</p>
    <label>Assunto<input id="ticket-subject" maxlength="200" required></label>
    <label>Descrição<textarea id="ticket-desc" rows="5" maxlength="5000"></textarea></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Abrir chamado</button></menu>
  </form>
</dialog>
<dialog id="close-dialog">
  <form method="dialog">
    <h3>Encerrar atendimento</h3>
    <label>Resolução (fica no chamado)<textarea id="close-resolution" rows="3"></textarea></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Encerrar</button></menu>
  </form>
</dialog>
<script src="/container/talk/connect/v1/assets/app.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
