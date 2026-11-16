// Connect Talk — Envio em massa (JS puro, sem build). Supervisor e gerentes.
const csrf = document.querySelector('meta[name=csrf]').content;
const isSup = document.body.dataset.sup === '1';
const isLab = document.body.dataset.lab === '1';
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtPhone = (p) => { const m = String(p || '').match(/^55(\d{2})(\d{4,5})(\d{4})$/); return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p; };
const dt = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');
const statusLabel = { draft: 'Rascunho', scheduled: 'Agendado', running: 'Enviando', paused: 'Pausado', done: 'Concluído', canceled: 'Cancelado' };
const rStatus = { queued: 'Na fila', sent: 'Enviada', delivered: 'Entregue', read: 'Lida', failed: 'Falhou', skipped: 'Fora do envio' };
const srcLabel = { first_name: 'Primeiro nome', name: 'Nome completo', phone: 'Telefone' };
const state = { campaigns: [], templates: [], tags: [], sectors: [], me: null, channels: [], editing: null, timer: null, poll: null };

async function api(path, opts = {}) {
  const res = await fetch(path, { method: opts.method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: opts.body ? JSON.stringify(opts.body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(data.error || 'Erro');
  return data;
}
const toast = (m) => { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.append(t); setTimeout(() => t.remove(), 3500); };
const hl = (body) => esc(body).replace(/\{\{\s*(\d+)\s*\}\}/g, '<mark>{{$1}}</mark>');

// ------------------------------------------------------------ lista de envios
function card(c) {
  const done = c.sent + c.failed;
  const base = Math.max(1, c.total - c.skipped);
  const pct = Math.round((100 * done) / base);
  const sec = state.sectors.find((s) => s.id === c.sector_id);
  const acts = {
    draft: '<button type="button" class="ghost" data-act="edit">Editar</button><button type="button" class="primary" data-act="start">Iniciar</button><button type="button" class="ghost danger" data-act="cancel">Descartar</button>',
    scheduled: '<button type="button" class="ghost" data-act="pause">Pausar</button><button type="button" class="ghost danger" data-act="cancel">Cancelar</button>',
    running: '<button type="button" class="ghost" data-act="pause">Pausar</button><button type="button" class="ghost danger" data-act="cancel">Cancelar</button>',
    paused: '<button type="button" class="primary" data-act="resume">Retomar</button><button type="button" class="ghost danger" data-act="cancel">Cancelar</button>',
  }[c.status] || '';
  const stat = (n, label, key) => `<li${key ? ` data-filter="${key}"` : ''}><b>${n}</b>${label}</li>`;
  return `<article class="card cp-card st-${c.status}" data-id="${c.id}">
    <header><div><b>${esc(c.name)}</b><span class="cp-st st-${c.status}">${statusLabel[c.status]}</span></div>
      <small class="meta">Modelo <code>${esc(c.template_name)}</code> · ${esc(c.channel_name)} · respostas para
        <span class="chip" style="--c:${esc(sec?.color || '#12804A')}">${esc(c.sector_name)}</span> · por ${esc((c.created_by_name || '').split(' (')[0])}
        ${c.status === 'scheduled' ? ` · começa ${dt(c.send_at)}` : c.started_at ? ` · início ${dt(c.started_at)}` : ''}${c.finished_at ? ` · fim ${dt(c.finished_at)}` : ''}</small></header>
    ${c.status === 'draft' ? `<p class="meta">Rascunho: a lista é montada ao iniciar.</p>` : `
    <div class="cp-bar" title="${pct}%"><i style="width:${pct}%"></i></div>
    <ul class="cp-stats">${stat(c.total - c.skipped, 'pessoas', '')}${stat(c.queued, 'na fila', 'queued')}${stat(c.sent, 'enviadas', 'sent')}${stat(c.delivered, 'entregues', 'delivered')}${stat(c.seen, 'lidas', 'read')}${stat(c.replied, 'responderam', 'replied')}${stat(c.failed, 'falhas', 'failed')}${stat(c.skipped, 'fora', 'skipped')}</ul>`}
    <footer>${c.status !== 'draft' ? '<button type="button" class="link" data-act="recipients">Ver destinatários</button>' : ''}<span class="cp-acts">${acts}</span></footer>
  </article>`;
}

async function loadCampaigns() {
  state.campaigns = await api('/api/campaigns');
  $('#cp-list').innerHTML = state.campaigns.map(card).join('')
    || `<div class="card empty-state"><p>Nenhum envio ainda.${state.templates.length ? ' Clique em “Novo envio”.' : ' Comece sincronizando ou cadastrando um modelo na aba “Modelos da Meta”.'}</p></div>`;
  clearTimeout(state.poll);
  if (state.campaigns.some((c) => c.status === 'running' || c.status === 'scheduled')) state.poll = setTimeout(loadCampaigns, 5000); // acompanha o envio
}

// ------------------------------------------------------------ modelos
function renderTemplates() {
  $('#n-tpl').textContent = state.templates.filter((t) => t.status === 'APPROVED').length || '';
  $('#tp-rows').innerHTML = state.templates.map((t) => `<tr data-id="${t.id}">
    <td><code>${esc(t.name)}</code><small class="sub">${esc(t.language)}${t.category ? ' · ' + esc(t.category) : ''}</small></td>
    <td class="tp-body">${hl(t.body)}</td><td>${t.variables}</td>
    <td><span class="cp-st ${t.status === 'APPROVED' ? 'st-done' : 'st-paused'}">${t.status === 'APPROVED' ? 'Aprovado' : esc(t.status)}</span></td>
    <td>${esc(t.channel_name)}</td>
    <td>${isSup && !t.used ? '<button type="button" class="ghost danger" data-act="del">Excluir</button>' : ''}</td></tr>`).join('')
    || '<tr><td colspan="6" class="empty-row">Nenhum modelo. Sincronize com a Meta ou cadastre.</td></tr>';
  $('#tp-actions').innerHTML = isSup ? state.channels.map((c) => c.kind === 'official'
    ? `<button type="button" class="ghost" data-sync="${c.id}">Sincronizar ${esc(c.name)}</button>` : '').join('') + '<button type="button" class="primary" id="tp-new">+ Cadastrar modelo</button>' : '';
}

async function loadTemplates() {
  state.templates = await api('/api/templates');
  renderTemplates();
}

// ------------------------------------------------------------ diálogo do envio
function bulkChannels() {
  const map = new Map();
  for (const t of state.templates) if (t.status === 'APPROVED') map.set(t.channel_id, t.channel_name);
  return [...map.entries()];
}

function fillTemplates(selected) {
  const ch = +$('#cp-channel').value;
  $('#cp-template').innerHTML = state.templates.filter((t) => t.channel_id === ch && t.status === 'APPROVED')
    .map((t) => `<option value="${t.id}">${esc(t.name)} (${esc(t.language)})</option>`).join('');
  if (selected) $('#cp-template').value = selected;
  fillVars();
}

function fillVars(map = null) {
  const t = state.templates.find((x) => x.id == $('#cp-template').value);
  const list = $('#cp-form').querySelector('[name=aud]:checked').value === 'list';
  const cur = map || readVars();
  let html = t ? `<legend>Texto do modelo</legend><p class="tp-body">${hl(t.body)}</p>` : '';
  for (let i = 1; t && i <= t.variables; i++) {
    const v = cur[i] || (i === 1 ? 'first_name' : '');
    const fixed = v.startsWith('fixed:') ? v.slice(6) : '';
    const opts = [...Object.entries(srcLabel), ...(list ? [3, 4, 5, 6].map((n) => [`col:${n}`, `Coluna ${n} da lista`]) : []), ['fixed', 'Texto fixo']];
    html += `<div class="cp-var"><mark>{{${i}}}</mark><select data-var="${i}">${opts.map(([k, l]) => `<option value="${k}"${(v.startsWith('fixed:') ? 'fixed' : v) === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
      <input data-fixed="${i}" placeholder="texto que vai para todos" value="${esc(fixed)}"${v.startsWith('fixed:') ? '' : ' hidden'}></div>`;
  }
  $('#cp-vars').innerHTML = html;
}

function readVars() {
  const out = {};
  document.querySelectorAll('#cp-vars [data-var]').forEach((s) => {
    const i = s.dataset.var;
    out[i] = s.value === 'fixed' ? `fixed:${$(`#cp-vars [data-fixed="${i}"]`).value}` : s.value;
  });
  return out;
}

function payload() {
  const f = $('#cp-form').elements;
  const aud = $('#cp-form').querySelector('[name=aud]:checked').value;
  return {
    name: f.namedItem('name').value, channel_id: +f.namedItem('channel_id').value, template_id: +f.namedItem('template_id').value,
    sector_id: +f.namedItem('sector_id').value, var_map: readVars(), per_minute: +f.namedItem('per_minute').value,
    audience: aud === 'tag' ? { type: 'tag', tag_id: +$('#cp-tag').value } : { type: 'list', rows: $('#cp-rows').value },
    send_at: $('#cp-when').value === 'later' ? $('#cp-at').value.replace('T', ' ') : '',
  };
}

function schedulePreview() { clearTimeout(state.timer); state.timer = setTimeout(preview, 400); }

async function preview() {
  try {
    const p = await api('/api/campaigns/preview', { method: 'POST', body: payload() });
    const skip = Object.entries(p.skipped).map(([k, n]) => `${n} ${esc(k)}`).join(', ');
    $('#cp-preview').innerHTML = `<p class="cp-count"><b>${p.total}</b> pessoa(s) vão receber${p.total ? ` · cerca de ${p.minutes} min` : ''}</p>
      ${skip ? `<p class="meta">Ficam de fora: ${skip}.</p>` : ''}
      ${p.samples.map((s) => `<div class="wa-bubble"><small>${esc(s.name || '')} · ${esc(fmtPhone(s.phone))}</small>${esc(s.text)}</div>`).join('')}`;
  } catch (e) {
    $('#cp-preview').innerHTML = `<p class="meta">${esc(e.message)}</p>`;
  }
}

function openDialog(c = null) {
  state.editing = c;
  const f = $('#cp-form'); f.reset();
  $('#cp-error').hidden = true;
  $('#cp-title').textContent = c ? `Editar ${c.name}` : 'Novo envio';
  const chans = bulkChannels();
  if (!chans.length) { toast('Cadastre ou sincronize um modelo aprovado antes (aba Modelos da Meta).'); return; }
  $('#cp-channel').innerHTML = chans.map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('');
  const mine = state.me.supervisor ? state.sectors : state.sectors.filter((s) => state.me.manager_sectors.includes(s.id));
  $('#cp-sector').innerHTML = mine.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  $('#cp-tag').innerHTML = state.tags.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('') || '<option value="">Nenhuma etiqueta</option>';
  if (c) {
    f.elements.namedItem('name').value = c.name; $('#cp-channel').value = c.channel_id; $('#cp-sector').value = c.sector_id;
    f.elements.namedItem('per_minute').value = c.per_minute;
    f.querySelector(`[name=aud][value=${c.audience.type}]`).checked = true;
    if (c.audience.type === 'tag') $('#cp-tag').value = c.audience.tag_id; else $('#cp-rows').value = c.audience.rows;
    $('#cp-when').value = c.send_at ? 'later' : 'now'; $('#cp-at').value = c.send_at ? c.send_at.slice(0, 16).replace(' ', 'T') : '';
  }
  syncAud();
  fillTemplates(c?.template_id);
  if (c) fillVars(c.var_map);
  $('#cp-at-box').hidden = $('#cp-when').value !== 'later';
  $('#cp-dialog').showModal();
  preview();
}

function syncAud() {
  const list = $('#cp-form').querySelector('[name=aud]:checked').value === 'list';
  $('#cp-tag').hidden = list; $('#cp-list-box').hidden = !list;
}

async function saveCampaign(start) {
  try {
    const c = await api(state.editing ? `/api/campaigns/${state.editing.id}` : '/api/campaigns', { method: 'POST', body: payload() });
    if (start) {
      const p = await api('/api/campaigns/preview', { method: 'POST', body: payload() });
      if (!confirm(`Enviar o modelo para ${p.total} pessoa(s)? Depois de enviada, a mensagem não volta.`)) { await loadCampaigns(); state.editing = c; return; }
      await api(`/api/campaigns/${c.id}/start`, { method: 'POST' });
    }
    $('#cp-dialog').close();
    toast(start ? 'Envio iniciado.' : 'Rascunho salvo.');
    await loadCampaigns();
  } catch (e) { $('#cp-error').textContent = e.message; $('#cp-error').hidden = false; }
}

// ------------------------------------------------------------ destinatários
async function showRecipients(c, filter = '') {
  const rows = await api(`/api/campaigns/${c.id}/recipients?status=${filter}`);
  $('#rc-title').textContent = `Destinatários · ${c.name}`;
  $('#rc-filter').innerHTML = [['', 'Todos'], ['sent', 'Enviadas'], ['delivered', 'Entregues'], ['read', 'Lidas'], ['replied', 'Responderam'], ['failed', 'Falhas'], ['skipped', 'Fora'], ['queued', 'Na fila']]
    .map(([k, l]) => `<button type="button" class="chip-btn${k === filter ? ' on' : ''}" data-f="${k}">${l}</button>`).join('');
  $('#rc-rows').innerHTML = rows.map((r) => `<tr><td><b>${esc(r.name || 'Sem nome')}</b><small class="sub">${esc(fmtPhone(r.phone))}</small></td>
    <td>${rStatus[r.status]}${r.replied_at ? ' · <b>respondeu</b>' : ''}</td><td class="meta">${esc(r.skip_reason || r.error || (r.sent_at ? dt(r.sent_at) : ''))}</td></tr>`).join('')
    || '<tr><td colspan="3" class="empty-row">Ninguém aqui.</td></tr>';
  $('#rc-filter').onclick = (ev) => { const b = ev.target.closest('[data-f]'); if (b) showRecipients(c, b.dataset.f); };
  if (!$('#rc-dialog').open) $('#rc-dialog').showModal();
}

// ------------------------------------------------------------ eventos
$('#cp-list').addEventListener('click', async (ev) => {
  const el = ev.target.closest('[data-act], [data-filter]');
  if (!el) return;
  const c = state.campaigns.find((x) => x.id == el.closest('[data-id]').dataset.id);
  if (el.dataset.filter !== undefined) return showRecipients(c, el.dataset.filter);
  const act = el.dataset.act;
  if (act === 'edit') return openDialog(c);
  if (act === 'recipients') return showRecipients(c);
  if (act === 'cancel' && !confirm(c.status === 'draft' ? 'Descartar este rascunho?' : 'Cancelar o envio? Quem ainda está na fila não recebe.')) return;
  if (act === 'start' && !confirm(`Iniciar o envio “${c.name}”?`)) return;
  try { await api(`/api/campaigns/${c.id}/${act}`, { method: 'POST' }); await loadCampaigns(); } catch (e) { toast(e.message); }
});
$('#cp-form').addEventListener('change', (ev) => {
  if (ev.target.id === 'cp-channel') fillTemplates();
  if (ev.target.id === 'cp-template') fillVars({});
  if (ev.target.name === 'aud') { syncAud(); fillVars(); }
  if (ev.target.dataset.var) $(`#cp-vars [data-fixed="${ev.target.dataset.var}"]`).hidden = ev.target.value !== 'fixed';
  if (ev.target.id === 'cp-when') $('#cp-at-box').hidden = ev.target.value !== 'later';
  schedulePreview();
});
$('#cp-form').addEventListener('input', (ev) => { if (ev.target.id === 'cp-rows' || ev.target.dataset.fixed) schedulePreview(); });
$('#cp-form').addEventListener('submit', (ev) => { ev.preventDefault(); saveCampaign(true); });
$('#cp-draft').addEventListener('click', () => saveCampaign(false));
$('#cp-form button[value=cancel]').addEventListener('click', (ev) => { ev.preventDefault(); $('#cp-dialog').close(); });
$('#cp-new').addEventListener('click', () => openDialog());
$('#rc-close').addEventListener('click', () => $('#rc-dialog').close());

document.querySelector('[data-panel=templates]').addEventListener('click', async (ev) => {
  if (ev.target.id === 'tp-new') {
    $('#tp-form').reset(); $('#tp-error').hidden = true;
    $('#tp-channel').innerHTML = state.channels.filter((c) => c.status === 'active' && (c.kind === 'official' || (isLab && c.kind === 'lab')))
      .map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
    return $('#tp-dialog').showModal();
  }
  const sync = ev.target.closest('[data-sync]');
  if (sync) {
    try { const r = await api('/api/templates/sync', { method: 'POST', body: { channel_id: +sync.dataset.sync } }); toast(`${r.synced} modelo(s) sincronizado(s).`); await loadTemplates(); } catch (e) { toast(e.message); }
    return;
  }
  const del = ev.target.closest('[data-act=del]');
  if (del && confirm('Excluir este modelo do Talk? (Na Meta ele continua.)')) {
    try { await api(`/api/templates/${del.closest('tr').dataset.id}/delete`, { method: 'POST' }); await loadTemplates(); } catch (e) { toast(e.message); }
  }
});
$('#tp-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = $('#tp-form').elements;
  try {
    await api('/api/templates', { method: 'POST', body: { channel_id: +f.namedItem('channel_id').value, name: f.namedItem('name').value, language: f.namedItem('language').value, body: f.namedItem('body').value } });
    $('#tp-dialog').close(); await loadTemplates();
  } catch (e) { $('#tp-error').textContent = e.message; $('#tp-error').hidden = false; }
});
$('#tp-form button[value=cancel]').addEventListener('click', (ev) => { ev.preventDefault(); $('#tp-dialog').close(); });
document.querySelector('.t-tabs').addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-tab]'); if (!b) return;
  document.querySelectorAll('.t-tabs [data-tab]').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
  document.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== b.dataset.tab; });
  $('#cp-new').hidden = b.dataset.tab !== 'campaigns';
});

(async () => {
  const me = await api('/api/me');
  state.me = me.user; state.sectors = me.sectors;
  [state.tags, state.channels] = await Promise.all([api('/api/tags'), isSup ? api('/api/channels') : Promise.resolve([])]);
  await loadTemplates();
  await loadCampaigns();
})();
