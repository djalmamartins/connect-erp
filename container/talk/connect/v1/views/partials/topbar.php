<?php defined('CONF_DB_NAME') || exit; ?>
<?php
/** Topo de todas as telas: busca de conversas, notificações e perfil (como o navbar do Connect ERP). */
$initials ??= '';
$onInbox = ($active ?? '') === 'inbox';
?>
<header class="topbar">
  <form class="tb-search" id="search-form" action="/" method="get" role="search">
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>
    <label class="sr-only" for="search">Buscar conversas</label>
    <input id="search" name="q" type="search" autocomplete="off" placeholder="Buscar conversas: nome, telefone, protocolo ou mensagem">
    <kbd title="Atalho">Ctrl K</kbd>
  </form>
  <div class="tb-actions">
    <details class="tb-drop" id="tb-notif">
      <summary class="tb-icon" aria-label="Notificações" title="Notificações"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16V11a6 6 0 1112 0v5l1.5 2h-15zM10 20a2 2 0 004 0"/></svg><span class="tb-count" id="tb-notif-n" hidden></span></summary>
      <div class="tb-panel wide" role="region" aria-label="Notificações">
        <h2>Notificações</h2>
        <div id="tb-notif-list"><p class="tb-empty">Carregando…</p></div>
      </div>
    </details>
    <details class="tb-drop">
      <summary class="tb-user" aria-label="Menu do usuário"><span class="avatar xs"><?= htmlspecialchars($initials) ?></span><span class="tb-user-txt"><b><?= htmlspecialchars($userName) ?></b><small class="live" id="live">● conectando</small></span><svg class="tb-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"/></svg></summary>
      <div class="tb-panel">
        <h2><?= htmlspecialchars($userName) ?><small><?= htmlspecialchars($userEmail ?? '') ?></small><small><?= htmlspecialchars($roleLabel ?? '') ?></small></h2>
        <button type="button" class="tb-item" id="sound-toggle" aria-pressed="true"><svg viewBox="0 0 24 24"><path d="M4 10v4h4l5 4V6L8 10zM16 9a4 4 0 010 6M18.5 6.5a7.5 7.5 0 010 11"/></svg><span><b>Som de alerta</b><small id="sound-state">Ligado: toca quando chega mensagem</small></span></button>
        <div class="tb-sep"></div>
        <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="tb-item"><svg viewBox="0 0 24 24"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg><span>Sair</span></button></form>
      </div>
    </details>
  </div>
</header>
