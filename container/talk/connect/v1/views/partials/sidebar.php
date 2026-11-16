<?php defined('CONF_DB_NAME') || exit; ?>
<?php
/** Menu lateral comum. $active: 'inbox' | 'queue' | 'contacts' | 'channels' | 'team' | 'schedules' | 'campaigns' | 'reports' | 'settings'. Fora da caixa de entrada, os diálogos abrem nela. */
$active ??= 'inbox';
$onInbox = $active === 'inbox';
$initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim(preg_replace('/\(.*\)/', '', $userName))), 0, 2))));
?>
<nav class="sidebar" aria-label="Navegação">
  <img class="logo" src="/container/talk/connect/v1/assets/logo.svg" alt="Connect Condomínios">
  <details class="envswitch">
    <summary title="Trocar de sistema"><span class="env-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v10H8l-4 4z"/></svg></span><span><small>Talk</small><strong>Connect Talk</strong></span><svg class="env-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></summary>
    <div class="envswitch-panel" role="menu" aria-label="Sistemas Connect">
      <small class="env-title">Sistemas Connect</small>
<?php foreach ($systems ?? [] as $sys): $tag = $sys['href'] === null ? 'span' : 'a'; ?>
      <<?= $tag ?> class="env-item<?= $sys['href'] === null ? ' off' : '' ?>"<?= $sys['href'] !== null ? ' href="' . htmlspecialchars($sys['href']) . '"' : '' ?><?= $sys['current'] ? ' aria-current="page"' : '' ?> role="menuitem">
        <span class="env-ico env-<?= htmlspecialchars($sys['key']) ?>" aria-hidden="true"><?= $sys['key'] === 'talk' ? '<svg viewBox="0 0 24 24"><path d="M4 5h16v10H8l-4 4z"/></svg>' : '<svg viewBox="0 0 24 24"><path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6M4 21h16"/></svg>' ?></span>
        <span><strong><?= htmlspecialchars($sys['label']) ?></strong><small><?= htmlspecialchars($sys['href'] === null ? 'Endereço ainda não configurado' : $sys['description']) ?></small></span>
        <?= $sys['current'] ? '<em>Atual</em>' : '' ?>
      </<?= $tag ?>>
<?php endforeach; ?>
    </div>
  </details>
  <a class="nav<?= $active === 'inbox' ? ' active' : '' ?>" href="/"><svg viewBox="0 0 24 24"><path d="M4 5h16v10H8l-4 4z"/></svg>Atendimentos <b id="badge-nav"></b></a>
  <a class="nav<?= $active === 'queue' ? ' active' : '' ?>" href="/fila"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M16 5a3 3 0 010 6M18 14c2 .5 3 2.4 3 5"/></svg>Fila de atendimento <b id="badge-queue"></b></a>
  <a class="nav<?= $active === 'reports' ? ' active' : '' ?>" href="/painel"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>Painel</a>
  <a class="nav<?= $active === 'schedules' ? ' active' : '' ?>" href="/agendamentos"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v3l2 1"/></svg>Agendamentos <b id="badge-schedules"></b></a>
<?php if (!empty($canBulk)): ?>
  <a class="nav<?= $active === 'campaigns' ? ' active' : '' ?>" href="/envios"><svg viewBox="0 0 24 24"><path d="M3 11v2a2 2 0 002 2h2l5 4V5L7 9H5a2 2 0 00-2 2zM16 8.5a5 5 0 010 7M19 6a8.5 8.5 0 010 12"/></svg>Envio em massa</a>
<?php endif; ?>
  <a class="nav<?= $active === 'contacts' ? ' active' : '' ?>" href="/contatos"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>Contatos</a>
<?php if (!empty($isSupervisor)): ?>
  <a class="nav<?= $active === 'team' ? ' active' : '' ?>" href="/equipe"><svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3.3 2.7-6 6-6s6 2.7 6 6M14 15.5c.9-.6 1.9-1 3-1 2.8 0 5 2.2 5 5"/></svg>Equipe e setores</a>
  <a class="nav<?= $active === 'channels' ? ' active' : '' ?>" href="/conexoes"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>Conexão WhatsApp</a>
  <a class="nav<?= $active === 'settings' ? ' active' : '' ?>" href="/configuracoes"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>Horário de atendimento</a>
<?php endif; ?>
  <a class="nav" href="<?= $onInbox ? '#' : '/?abrir=mensagens' ?>" id="nav-quick"><svg viewBox="0 0 24 24"><path d="M13 3L5 13h6l-1 8 8-10h-6z"/></svg>Mensagens rápidas</a>
  <a class="nav" href="<?= $onInbox ? '#' : '/?abrir=etiquetas' ?>" id="nav-tags"><svg viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/></svg>Etiquetas</a>
</nav>
<dialog id="pw-dialog">
  <form method="dialog" id="pw-form">
    <h3>Trocar minha senha</h3>
    <label>Senha atual<input type="password" name="current" autocomplete="current-password" required></label>
    <label>Nova senha<input type="password" name="new" autocomplete="new-password" minlength="8" maxlength="72" required></label>
    <label>Repita a nova senha<input type="password" name="again" autocomplete="new-password" required></label>
    <p class="meta">Pelo menos 8 caracteres, com letras e números. Você continua conectado aqui; em outros aparelhos será preciso entrar de novo.</p>
    <p class="form-error" id="pw-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Salvar</button></menu>
  </form>
</dialog>
<script src="/container/talk/connect/v1/assets/account.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
<script src="/container/talk/connect/v1/assets/topbar.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
