// Connect Talk — Equipe e setores (JS puro, sem build). Só o supervisor chega aqui.
const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const initials = (n) => (n || '?').replace(/\(.*\)/, '').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const dt = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');
const roleLabel = { agent: 'Atendente', manager: 'Gerente', supervisor: 'Supervisor' };
const actionLabel = {
  'user.create': 'cadastrou o atendente', 'user.update': 'alterou o atendente', 'user.activate': 'reativou o atendente',
  'user.deactivate': 'desativou o atendente', 'user.password': 'trocou a própria senha',
  'sector.create': 'criou o setor', 'sector.update': 'alterou o setor', 'sector.activate': 'reativou o setor', 'sector.deactivate': 'desativou o setor',
};
const state = { tab: 'users', users: [], sectors: [], me: null, editing: null, editingSector: null };

async function api(path, opts = {}) {
  const res = await fetch(path, { method: opts.method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: opts.body ? JSON.stringify(opts.body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(data.error || 'Erro');
  return data;
}
const toast = (m) => { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.append(t); setTimeout(() => t.remove(), 3500); };
const sectorChip = (id) => { const s = state.sectors.find((x) => x.id === id); return s ? `<span class="chip" style="--c:${esc(s.color)}">${esc(s.name)}</span>` : ''; };

// ------------------------------------------------------------ atendentes
function renderUsers() {
  const q = $('#t-q').value.trim().toLowerCase();
  const sec = $('#t-sector').value;
  const st = $('#t-status').value;
  const list = state.users.filter((u) => (!q || `${u.name} ${u.email}`.toLowerCase().includes(q))
    && (!sec || u.sector_ids.includes(+sec) || u.role === 'supervisor')
    && (!st || (st === 'active') === u.active));
  $('#t-users-n').textContent = state.users.filter((u) => u.active).length;
  $('#t-rows').innerHTML = list.map((u) => {
    const status = !u.active ? '<span class="t-st off">Desativado</span>' : u.online ? (u.open_count ? '<span class="t-st busy">Atendendo</span>' : '<span class="t-st on">Disponível</span>')
      : `<span class="t-st">Offline${u.last_seen_at ? ` <small>visto ${dt(u.last_seen_at)}</small>` : ''}</span>`;
    return `<tr data-id="${u.id}" class="${u.active ? '' : 'inactive'}">
      <td><div class="who"><span class="avatar sm">${esc(initials(u.name))}</span><div><b>${esc(u.name)}${u.id === state.me ? ' <small class="you">você</small>' : ''}</b><small>${esc(u.email)}</small></div></div></td>
      <td><span class="role role-${u.role}">${roleLabel[u.role]}</span></td>
      <td><div class="chips">${u.role === 'supervisor' ? '<span class="meta">Todos</span>' : u.sector_ids.map(sectorChip).join('')}</div></td>
      <td>${status}</td><td>${u.open_count}</td><td>${u.closed_today}</td>
      <td class="t-actions"><button type="button" class="ghost" data-act="edit">Editar</button>
        ${u.id === state.me ? '' : `<button type="button" class="ghost${u.active ? ' danger' : ''}" data-act="${u.active ? 'deactivate' : 'activate'}">${u.active ? 'Desativar' : 'Reativar'}</button>`}</td></tr>`;
  }).join('') || '<tr><td colspan="7" class="empty-row">Ninguém encontrado.</td></tr>';
}

function syncRole() {
  const sup = $('#u-form').querySelector('[name=role]:checked').value === 'supervisor';
  $('#u-sectors').disabled = sup;
  $('#u-sectors legend').textContent = sup ? 'Setores (o supervisor vê todos)' : 'Setores';
}

function openUser(u = null) {
  state.editing = u;
  const f = $('#u-form');
  f.reset();
  $('#u-error').hidden = true;
  $('#u-title').textContent = u ? `Editar ${u.name}` : 'Novo atendente';
  $('#u-pw-label').textContent = u ? 'Nova senha (em branco: mantém)' : 'Senha inicial';
  f.elements.namedItem('password').required = !u;
  $('#u-sectors').innerHTML = '<legend>Setores</legend>' + state.sectors.filter((s) => s.active || (u && u.sector_ids.includes(s.id)))
    .map((s) => `<label><input type="checkbox" name="sector" value="${s.id}"${u && u.sector_ids.includes(s.id) ? ' checked' : ''}><span class="chip" style="--c:${esc(s.color)}">${esc(s.name)}</span></label>`).join('');
  if (u) {
    f.elements.namedItem('name').value = u.name; f.elements.namedItem('email').value = u.email;
    f.elements.namedItem('erp_user_id').value = u.erp_user_id ?? '';
    f.querySelector(`[name=role][value=${u.role}]`).checked = true;
  }
  syncRole();
  $('#u-dialog').showModal();
}

async function saveUser(ev) {
  ev.preventDefault();
  const f = $('#u-form');
  const body = {
    name: f.elements.namedItem('name').value, email: f.elements.namedItem('email').value, password: f.elements.namedItem('password').value,
    erp_user_id: f.elements.namedItem('erp_user_id').value, role: f.querySelector('[name=role]:checked').value,
    sector_ids: [...f.querySelectorAll('[name=sector]:checked')].map((i) => +i.value),
  };
  try {
    await api(state.editing ? `/api/team/users/${state.editing.id}` : '/api/team/users', { method: 'POST', body });
    $('#u-dialog').close();
    toast(state.editing ? 'Atendente atualizado.' : 'Atendente cadastrado.');
    await load();
  } catch (e) { $('#u-error').textContent = e.message; $('#u-error').hidden = false; }
}

// ------------------------------------------------------------ setores
function renderSectors() {
  $('#t-sectors-n').textContent = state.sectors.filter((s) => s.active).length;
  $('#s-cards').innerHTML = state.sectors.map((s) => `
    <article class="card s-card${s.active ? '' : ' inactive'}" data-id="${s.id}" style="--c:${esc(s.color)}">
      <header><b>${esc(s.name)}</b>${s.jack ? '<span class="badge-ok" title="A Jack encaminha para este setor">Jack</span>' : ''}${s.active ? '' : '<span class="badge-off">Desativado</span>'}</header>
      <p class="meta">${esc(s.description || (s.jack ? 'Recebe as conversas que a Jack identifica.' : 'Recebe por transferência ou por um número dedicado.'))}</p>
      <ul class="s-nums"><li><b>${s.people}</b>pessoas</li><li><b>${s.waiting}</b>na fila</li><li><b>${s.open_count}</b>em atendimento</li><li><b>${s.channels}</b>números</li></ul>
      <div class="people">${state.users.filter((u) => u.active && u.sector_ids.includes(s.id)).slice(0, 6).map((u) => `<span class="avatar xs" title="${esc(u.name)} (${roleLabel[u.role]})">${esc(initials(u.name))}</span>`).join('')}</div>
      <footer><button type="button" class="ghost" data-act="edit">Editar</button>
        ${s.jack ? '' : `<button type="button" class="ghost${s.active ? ' danger' : ''}" data-act="${s.active ? 'deactivate' : 'activate'}">${s.active ? 'Desativar' : 'Reativar'}</button>`}</footer>
    </article>`).join('') + '<button type="button" class="s-add" id="s-new">+ Novo setor</button>';
}

function openSector(s = null) {
  state.editingSector = s;
  const f = $('#s-form');
  f.reset();
  $('#s-error').hidden = true;
  $('#s-title').textContent = s ? `Editar ${s.name}` : 'Novo setor';
  $('#s-jack').hidden = !(s && s.jack);
  if (s) { f.elements.namedItem('name').value = s.name; f.elements.namedItem('description').value = s.description || ''; f.elements.namedItem('color').value = s.color; }
  $('#s-dialog').showModal();
}

async function saveSector(ev) {
  ev.preventDefault();
  const f = $('#s-form');
  const body = { name: f.elements.namedItem('name').value, description: f.elements.namedItem('description').value, color: f.elements.namedItem('color').value };
  try {
    await api(state.editingSector ? `/api/team/sectors/${state.editingSector.id}` : '/api/team/sectors', { method: 'POST', body });
    $('#s-dialog').close();
    await load();
  } catch (e) { $('#s-error').textContent = e.message; $('#s-error').hidden = false; }
}

// ------------------------------------------------------------ registro
async function renderAudit() {
  const rows = await api('/api/team/audit?limit=100');
  const name = (r) => (r.target_type === 'user' ? state.users.find((u) => u.id == r.target_id)?.name : state.sectors.find((s) => s.id == r.target_id)?.name) || `#${r.target_id}`;
  $('#a-rows').innerHTML = rows.map((r) => {
    let d = {}; try { d = JSON.parse(r.details || '{}') || {}; } catch { /* sem detalhes */ }
    const extra = [d.role ? roleLabel[d.role] : '', d.password_changed && r.action === 'user.update' ? 'senha redefinida' : '', d.returned_to_queue ? `${d.returned_to_queue} atendimento(s) de volta à fila` : ''].filter(Boolean).join(' · ');
    return `<li><time>${dt(r.created_at)}</time><span><b>${esc((r.user_name || 'Sistema').split(' (')[0])}</b> ${actionLabel[r.action] || esc(r.action)} <b>${esc(name(r))}</b>${extra ? ` <small>${esc(extra)}</small>` : ''}</span></li>`;
  }).join('') || '<li class="meta">Nenhuma alteração registrada ainda.</li>';
}

// ------------------------------------------------------------ geral
function setTab(tab) {
  state.tab = tab;
  document.querySelectorAll('.t-tabs [data-tab]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
  document.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== tab; });
  $('#t-new').hidden = tab !== 'users';
  if (tab === 'audit') renderAudit();
  history.replaceState(null, '', tab === 'users' ? '/equipe' : `/equipe?aba=${tab}`);
}

async function load() {
  [state.users, state.sectors] = await Promise.all([api('/api/team/users'), api('/api/team/sectors')]);
  const sel = $('#t-sector').value;
  $('#t-sector').innerHTML = '<option value="">Todos os setores</option>' + state.sectors.filter((s) => s.active).map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  $('#t-sector').value = sel;
  renderUsers();
  renderSectors();
  if (state.tab === 'audit') renderAudit();
}

document.querySelector('.t-tabs').addEventListener('click', (ev) => { const b = ev.target.closest('[data-tab]'); if (b) setTab(b.dataset.tab); });
['#t-q', '#t-sector', '#t-status'].forEach((s) => $(s).addEventListener('input', renderUsers));
$('#t-new').addEventListener('click', () => openUser());
$('#u-form').addEventListener('submit', saveUser);
$('#u-form').addEventListener('change', (ev) => { if (ev.target.name === 'role') syncRole(); });
$('#s-form').addEventListener('submit', saveSector);
document.querySelectorAll('#u-form button[value=cancel], #s-form button[value=cancel]').forEach((b) => b.addEventListener('click', (ev) => { ev.preventDefault(); b.closest('dialog').close(); }));

$('#t-rows').addEventListener('click', async (ev) => {
  const b = ev.target.closest('[data-act]');
  if (!b) return;
  const u = state.users.find((x) => x.id == b.closest('tr').dataset.id);
  if (b.dataset.act === 'edit') return openUser(u);
  if (b.dataset.act === 'deactivate' && !confirm(`Desativar ${u.name}? A pessoa não consegue mais entrar e os atendimentos dela (${u.open_count}) voltam para a fila do setor.`)) return;
  try {
    const r = await api(`/api/team/users/${u.id}/${b.dataset.act}`, { method: 'POST' });
    toast(b.dataset.act === 'deactivate' ? `${u.name} desativado.${r.returned_to_queue ? ` ${r.returned_to_queue} atendimento(s) voltaram para a fila.` : ''}` : `${u.name} reativado.`);
    await load();
  } catch (e) { toast(e.message); }
});

$('#s-cards').addEventListener('click', async (ev) => {
  if (ev.target.id === 's-new') return openSector();
  const b = ev.target.closest('[data-act]');
  if (!b) return;
  const s = state.sectors.find((x) => x.id == b.closest('.s-card').dataset.id);
  if (b.dataset.act === 'edit') return openSector(s);
  if (b.dataset.act === 'deactivate' && !confirm(`Desativar o setor ${s.name}? Ele some das listas de transferência; números dedicados a ele voltam para a Jack.`)) return;
  try { await api(`/api/team/sectors/${s.id}/${b.dataset.act}`, { method: 'POST' }); await load(); } catch (e) { toast(e.message); }
});

(async () => {
  state.me = (await api('/api/me')).user.id;
  await load();
  const aba = new URLSearchParams(location.search).get('aba');
  if (aba === 'sectors' || aba === 'audit') setTab(aba);
})();
