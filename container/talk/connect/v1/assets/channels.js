// Connect Talk — Conexão WhatsApp (JS puro, sem build). Só o supervisor chega aqui.
const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtPhone = (p) => { const m = String(p || '').match(/^55(\d{2})(\d{4,5})(\d{4})$/); return m ? `+55 (${m[1]}) ${m[2]}-${m[3]}` : (p || 'Número ainda não informado'); };
const initials = (n) => (n || '?').replace(/\(.*\)/, '').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const dt = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');
const kindLabel = { qrcode: 'QR Code', official: 'API oficial', lab: 'Laboratório' };
const purposeLabel = { attendance: 'Atendimento', bulk: 'Envios em massa', both: 'Atendimento e envios' };
const stateLabel = { connected: 'Conectado', connecting: 'Aguardando leitura', disconnected: 'Desconectado', unknown: 'Não verificado' };
const state = { channels: [], sectors: [], editing: null, qrFor: null, poll: null };

async function api(path, opts = {}) {
  const res = await fetch(path, { method: opts.method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: opts.body ? JSON.stringify(opts.body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(data.error || 'Erro');
  return data;
}

function row(c) {
  const st = c.status === 'disabled' ? 'disabled' : c.connection_state;
  const people = c.attendants.slice(0, 5).map((p) => `<span class="avatar xs" title="${esc(p.name)}">${esc(initials(p.name))}</span>`).join('')
    + (c.attendants.length > 5 ? `<span class="avatar xs more">+${c.attendants.length - 5}</span>` : '');
  const canConnect = c.status !== 'disabled' && c.connection_state !== 'connected';
  return `<li class="ch-row${c.status === 'disabled' ? ' off' : ''}" data-id="${c.id}">
    <span class="ch-dot st-${st}" aria-hidden="true"></span>
    <div class="ch-main">
      <div class="ch-name"><b>${esc(c.name)}</b>${c.is_default ? '<span class="badge-ok">Padrão</span>' : ''}</div>
      <small>${esc(fmtPhone(c.phone))} · ${kindLabel[c.kind]} · ${purposeLabel[c.purpose]}</small>
      <small class="ch-state st-${st}">${c.status === 'disabled' ? 'Desativado' : stateLabel[c.connection_state]}${c.state_checked_at ? ` · conferido ${dt(c.state_checked_at)}` : ''}${c.last_error ? ` · ${esc(c.last_error)}` : ''}</small>
      ${c.kind === 'official' ? `<small class="ch-hook">Webhook: <code>${esc(c.webhook_url)}</code> · token de verificação: <code>${esc(c.config.verify_token)}</code></small>` : ''}
    </div>
    <div class="ch-sector"><span class="chip" style="--c:${c.sector_id ? '#12804A' : '#6a756f'}">${esc(c.sector_name || 'Todos os setores (Jack)')}</span>
      <div class="ch-people">${people || '<span class="meta">Sem atendentes</span>'}</div></div>
    <div class="ch-actions">
      ${canConnect ? `<button type="button" class="primary" data-act="connect">${c.kind === 'official' ? 'Verificar' : 'Conectar'}</button>` : ''}
      ${c.status !== 'disabled' && c.connection_state === 'connected' ? '<button type="button" class="ghost" data-act="state">Conferir</button>' : ''}
      <button type="button" class="ghost icon" data-act="edit" title="Configurar" aria-label="Configurar ${esc(c.name)}">⚙</button>
      <details class="ch-more"><summary class="ghost icon" aria-label="Mais ações">⋯</summary><menu>
        ${!c.is_default && c.status !== 'disabled' ? '<button type="button" data-act="default">Tornar padrão</button>' : ''}
        ${c.kind === 'qrcode' && c.connection_state === 'connected' ? '<button type="button" data-act="logout">Desconectar o celular</button>' : ''}
        <button type="button" data-act="toggle">${c.status === 'disabled' ? 'Ativar' : 'Desativar'}</button>
        ${c.conversations === 0 ? '<button type="button" data-act="delete" class="danger">Excluir</button>' : ''}
      </menu></details>
    </div></li>`;
}

async function load() {
  state.channels = await api('/api/channels');
  $('#ch-count').textContent = `${state.channels.length} número(s)`;
  $('#ch-rows').innerHTML = state.channels.map(row).join('') || '<li class="meta">Nenhum número ainda. Clique em “Conectar novo número”.</li>';
}

function setKind(kind) {
  document.querySelectorAll('#ch-form [data-kind]').forEach((el) => { el.hidden = el.dataset.kind !== kind; });
  // QR Code novo: só nome e número. O setor aparece ao configurar depois; o uso é sempre atendimento.
  $('#ch-purpose-field').hidden = kind === 'qrcode';
  $('#ch-sector-field').hidden = kind === 'qrcode' && !state.editing;
  $('#ch-form').elements.namedItem('phone').required = kind === 'qrcode';
}

function openForm(c = null) {
  state.editing = c;
  const f = $('#ch-form');
  f.reset();
  $('#ch-error').hidden = true;
  $('#ch-form-title').textContent = c ? `Configurar ${c.name}` : 'Conectar novo número';
  $('#ch-kind').disabled = !!c;
  $('#ch-secret-hint').hidden = !c;
  const kind = c ? c.kind : 'qrcode';
  f.querySelector(`[name=kind][value=${kind}]`).checked = true;
  setKind(kind);
  if (c) {
    f.elements.namedItem('name').value = c.name; f.elements.namedItem('phone').value = c.kind === 'qrcode' && c.phone ? fmtPhone(c.phone).replace('+55 ', '') : (c.phone || ''); f.elements.namedItem('sector_id').value = c.sector_id ?? ''; f.elements.namedItem('purpose').value = c.purpose;
    for (const [k, v] of Object.entries(c.config)) {
      const input = f.querySelector(`[name="cfg.${k}"]`);
      if (input) { if (input.type === 'password') input.placeholder = v ? `salvo (${v})` : ''; else input.value = v; }
    }
  }
  $('#ch-dialog').showModal();
}

async function save(ev) {
  ev.preventDefault();
  const f = $('#ch-form');
  const kind = f.querySelector('[name=kind]:checked').value;
  const config = {};
  f.querySelectorAll(`[data-kind="${kind}"] [name^="cfg."]`).forEach((i) => { config[i.name.slice(4)] = i.value; });
  const body = { kind, name: f.elements.namedItem('name').value, phone: f.elements.namedItem('phone').value, sector_id: f.elements.namedItem('sector_id').value, purpose: f.elements.namedItem('purpose').value, config };
  try {
    const c = await api(state.editing ? `/api/channels/${state.editing.id}` : '/api/channels', { method: 'POST', body });
    $('#ch-dialog').close();
    await load();
    if (!state.editing && c.kind !== 'official') connect(c.id); // número novo: já mostra o QR Code
  } catch (e) {
    $('#ch-error').textContent = e.message; $('#ch-error').hidden = false;
  }
}

function stopPoll() { clearInterval(state.poll); state.poll = null; }

function showQr(r) {
  $('#qr-img').src = r.qr;
  $('#qr-state').textContent = 'Aguardando a leitura…';
  $('#qr-error').hidden = !r.detail; $('#qr-error').textContent = r.detail || '';
  stopPoll();
  let ticks = 0;
  state.poll = setInterval(async () => {
    try {
      const s = await api(`/api/channels/${state.qrFor}/state`, { method: 'POST' });
      if (s.state === 'connected') return done();
      // o QR do WhatsApp vale uns 20 segundos: troca a imagem sozinho, como no WhatsApp Web
      if (++ticks % 7 === 0) {
        const n = await api(`/api/channels/${state.qrFor}/connect`, { method: 'POST' });
        if (n.qr) $('#qr-img').src = n.qr; else if (n.state === 'connected') done();
      }
    } catch (e) { $('#qr-error').textContent = e.message; $('#qr-error').hidden = false; }
  }, 3000);
}

async function done() {
  stopPoll();
  $('#qr-state').textContent = 'Conectado!';
  await load();
  setTimeout(() => $('#qr-dialog').open && $('#qr-dialog').close(), 900);
}

async function connect(id) {
  const c = state.channels.find((x) => x.id == id);
  // QR Code: abre a janela na hora e mostra "Gerando…" enquanto o WhatsApp prepara o código
  const qrKind = c && c.kind !== 'official';
  if (qrKind) {
    stopPoll();
    state.qrFor = id;
    $('#qr-title').textContent = `Ler o QR Code · ${c.name}`;
    $('#qr-sim').hidden = !c.simulated;
    $('#qr-img').removeAttribute('src');
    $('#qr-img').closest('figure').classList.add('loading');
    $('#qr-state').textContent = 'Gerando o QR Code…';
    $('#qr-error').hidden = true;
    $('#qr-dialog').showModal();
  }
  try {
    const r = await api(`/api/channels/${id}/connect`, { method: 'POST' });
    $('#qr-img').closest('figure').classList.remove('loading');
    if (!r.qr) {
      await load();
      if (r.state === 'connected') { if (qrKind) done(); return; }
      if (qrKind) { $('#qr-state').textContent = ''; $('#qr-error').textContent = r.detail || 'Não conectou.'; $('#qr-error').hidden = false; return; }
      alertRow(id, r.detail || 'Não conectou. Confira os dados da conexão.');
      return;
    }
    showQr(r);
  } catch (e) {
    $('#qr-img').closest('figure').classList.remove('loading');
    await load();
    if (qrKind) { $('#qr-state').textContent = ''; $('#qr-error').textContent = e.message; $('#qr-error').hidden = false; } else alertRow(id, e.message);
  }
}

function alertRow(id, msg) {
  const li = $(`.ch-row[data-id="${id}"] .ch-main`);
  if (li) li.insertAdjacentHTML('beforeend', `<small class="form-error">${esc(msg)}</small>`);
}

$('#ch-rows').addEventListener('click', async (ev) => {
  const b = ev.target.closest('[data-act]');
  if (!b) return;
  const id = b.closest('.ch-row').dataset.id;
  const c = state.channels.find((x) => x.id == id);
  b.closest('details')?.removeAttribute('open');
  const act = b.dataset.act;
  try {
    if (act === 'edit') return openForm(c);
    if (act === 'connect') return connect(id);
    if (act === 'delete' && !confirm(`Excluir a conexão “${c.name}”?`)) return;
    if (act === 'logout' && !confirm(`Desconectar o celular de “${c.name}”? Será preciso ler o QR Code de novo.`)) return;
    if (act === 'toggle' && c.status !== 'disabled' && !confirm(`Desativar “${c.name}”? Mensagens novas neste número deixam de entrar no Talk.`)) return;
    await api(`/api/channels/${id}/${act}`, { method: 'POST' });
    await load();
  } catch (e) { alertRow(id, e.message); }
});

$('#ch-new').addEventListener('click', () => openForm());
$('#ch-form').addEventListener('submit', save);
$('#ch-form').addEventListener('change', (ev) => { if (ev.target.name === 'kind') setKind(ev.target.value); });
$('#ch-form').querySelector('button[value=cancel]').addEventListener('click', (ev) => { ev.preventDefault(); $('#ch-dialog').close(); });
$('#qr-close').addEventListener('click', () => { stopPoll(); $('#qr-dialog').close(); load(); });
$('#qr-dialog').addEventListener('close', stopPoll);
$('#qr-renew').addEventListener('click', () => connect(state.qrFor));
$('#qr-sim')?.addEventListener('click', async () => { await api(`/api/channels/${state.qrFor}/simulate-scan`, { method: 'POST' }); });

(async () => {
  const me = await api('/api/me');
  state.sectors = me.sectors;
  $('#ch-sector').insertAdjacentHTML('beforeend', me.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join(''));
  await load();
})();
