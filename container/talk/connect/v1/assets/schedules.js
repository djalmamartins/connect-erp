// Connect Talk — Agendamentos (JS puro, sem build)
const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtPhone = (p) => { const m = String(p || '').match(/^55(\d{2})(\d{4,5})(\d{4})$/); return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p; };
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parse = (s) => new Date(String(s).replace(' ', 'T'));
const hm = (s) => { const d = parse(s); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const statusLabel = { scheduled: 'Agendado', sending: 'Enviando', sent: 'Enviado', canceled: 'Cancelado', failed: 'Falhou' };
const state = { tab: 'scheduled', items: [], me: null, sectors: [], editing: null, contact: null, conv: null };

async function api(path, opts = {}) {
  const res = await fetch(path, { method: opts.method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: opts.body ? JSON.stringify(opts.body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(data.error || 'Erro');
  return data;
}
const toast = (m) => { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.append(t); setTimeout(() => t.remove(), 3500); };

function dayLabel(s) {
  const d = parse(s); const today = new Date(); const tomorrow = new Date(); tomorrow.setDate(today.getDate() + 1);
  const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
  if (ymd(d) === ymd(today)) return 'Hoje';
  if (ymd(d) === ymd(tomorrow)) return 'Amanhã';
  if (ymd(d) === ymd(yesterday)) return 'Ontem';
  const txt = d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
  return txt.charAt(0).toUpperCase() + txt.slice(1);
}

async function load() {
  const r = await api(`/api/schedules?status=${state.tab}`);
  state.items = r.items;
  for (const k of ['scheduled', 'sent', 'canceled', 'failed']) $(`#n-${k}`).textContent = r.counts[k] || '';
  const groups = new Map();
  for (const it of r.items) {
    const key = ymd(parse(state.tab === 'scheduled' ? it.send_at : (it.sent_at || it.send_at)));
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(it);
  }
  $('#sc-list').innerHTML = [...groups.values()].map((list) => `
    <section class="sc-day"><h2>${dayLabel(state.tab === 'scheduled' ? list[0].send_at : (list[0].sent_at || list[0].send_at))}</h2>
      <ul>${list.map(item).join('')}</ul></section>`).join('')
    || `<div class="card empty-state"><p>${state.tab === 'scheduled' ? 'Nenhuma mensagem agendada. Use “Novo agendamento” ou o relógio no campo de resposta da conversa.' : 'Nada por aqui.'}</p></div>`;
}

function item(it) {
  const when = state.tab === 'sent' && it.sent_at ? hm(it.sent_at) : hm(it.send_at);
  return `<li class="card sc-item st-${it.status}" data-id="${it.id}">
    <time>${when}</time>
    <div class="sc-body">
      <div class="sc-who"><b>${esc(it.contact_name || 'Sem nome')}</b><small>${esc(fmtPhone(it.phone))}</small>
        <span class="chip" style="--c:${esc(state.sectors.find((x) => x.id === it.sector_id)?.color || '#12804A')}">${esc(it.sector_name)}</span>${it.contact_blocked ? '<span class="badge-block">Bloqueado</span>' : ''}</div>
      <p>${esc(it.body)}</p>
      <small class="meta">Agendado por ${esc((it.created_by_name || '—').split(' (')[0])} · pelo número ${esc(it.channel_name)}${it.sign ? ' · assinado' : ''}
        ${it.status !== 'scheduled' ? ` · <span class="sc-st">${statusLabel[it.status]}${it.status === 'sent' && it.send_at !== it.sent_at ? ` (marcado para ${hm(it.send_at)})` : ''}</span>` : ''}
        ${it.error ? ` · <span class="form-error">${esc(it.error)}</span>` : ''}</small>
    </div>
    <div class="sc-actions">
      ${it.conversation_id ? `<a class="ghost btn" href="/?c=${it.conversation_id}">Abrir conversa</a>` : ''}
      ${it.can_edit ? '<button type="button" class="ghost" data-act="edit">Editar</button><button type="button" class="ghost danger" data-act="cancel">Cancelar</button>' : ''}
    </div></li>`;
}

// ------------------------------------------------------------ diálogo
function quickChips() {
  const now = new Date();
  const opts = [];
  const add = (label, d) => opts.push(`<button type="button" class="chip-btn" data-when="${ymd(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}">${label}</button>`);
  const in1h = new Date(now.getTime() + 3600e3); in1h.setMinutes(Math.ceil(in1h.getMinutes() / 5) * 5, 0, 0); add('Daqui a 1 hora', in1h);
  const t9 = new Date(now); t9.setDate(now.getDate() + 1); t9.setHours(9, 0, 0, 0); add('Amanhã às 9h', t9);
  const t14 = new Date(t9); t14.setHours(14); add('Amanhã às 14h', t14);
  const mon = new Date(now); mon.setDate(now.getDate() + ((8 - now.getDay()) % 7 || 7)); mon.setHours(9, 0, 0, 0); add('Segunda às 9h', mon);
  $('#sc-quick').innerHTML = opts.join('');
}

function setWhen(s) {
  const [d, t] = s.split(' ');
  const f = $('#sc-form').elements; f.namedItem('date').value = d; f.namedItem('time').value = t.slice(0, 5);
}

function chooseContact(c) {
  state.contact = c;
  $('#sc-suggest').hidden = true;
  $('#sc-chosen').hidden = !c;
  $('#sc-contact').closest('label').hidden = !!c;
  $('#sc-newname').hidden = !(c && c.isNew);
  if (c) $('#sc-chosen').innerHTML = `<span class="avatar xs">${esc((c.name || '?')[0].toUpperCase())}</span><div><b>${esc(c.name || (c.isNew ? 'Número novo' : 'Sem nome'))}</b><small>${esc(fmtPhone(c.phone))}</small></div><button type="button" class="link" id="sc-change">trocar</button>`;
}

async function openDialog(it = null, conv = null) {
  state.editing = it; state.conv = conv; state.contact = null;
  const f = $('#sc-form'); f.reset();
  $('#sc-error').hidden = true;
  $('#sc-title').textContent = it ? 'Editar agendamento' : 'Novo agendamento';
  $('#sc-save').textContent = it ? 'Salvar' : 'Agendar';
  const fixed = it || conv;
  $('#sc-to-pick').hidden = !!fixed;
  $('#sc-to-conv').hidden = !fixed;
  if (fixed) {
    const name = it ? it.contact_name : (conv.contact.name || 'Sem nome');
    const phone = it ? it.phone : conv.contact.phone;
    const sector = it ? it.sector_name : (state.sectors.find((s) => s.id === conv.conversation.sector_id)?.name || '—');
    $('#sc-to-conv').innerHTML = `<span class="avatar xs">${esc((name || '?')[0].toUpperCase())}</span><div><b>${esc(name)}</b><small>${esc(fmtPhone(phone))}${conv ? ` · protocolo ${esc(conv.conversation.protocol)}` : ''}</small></div><span class="chip" style="--c:#12804A">${esc(sector)}</span>`;
  } else {
    chooseContact(null);
    const mine = state.me.supervisor ? state.sectors : state.sectors.filter((s) => state.me.agent_sectors.includes(s.id) || state.me.manager_sectors.includes(s.id));
    $('#sc-sector').innerHTML = mine.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  }
  quickChips();
  if (it) { setWhen(it.send_at); f.elements.namedItem('body').value = it.body; f.elements.namedItem('sign').checked = it.sign; }
  else setWhen($('#sc-quick [data-when]:nth-child(2)').dataset.when);
  $('#sc-count').textContent = `${f.elements.namedItem('body').value.length}/4096`;
  $('#sc-dialog').showModal();
}

let searchTimer = null;
$('#sc-contact').addEventListener('input', (ev) => {
  clearTimeout(searchTimer);
  const q = ev.target.value.trim();
  if (q.length < 2) { $('#sc-suggest').hidden = true; return; }
  searchTimer = setTimeout(async () => {
    const r = await api(`/api/contacts?q=${encodeURIComponent(q)}`);
    const digits = q.replace(/\D/g, '');
    const items = r.items.slice(0, 6).map((c) => `<li data-id="${c.id}" data-name="${esc(c.name || '')}" data-phone="${esc(c.phone)}"><b>${esc(c.name || 'Sem nome')}</b><small>${esc(fmtPhone(c.phone))}</small></li>`);
    if (digits.length >= 10 && digits.length <= 13 && !r.items.some((c) => c.phone.endsWith(digits.slice(-8)))) {
      items.push(`<li data-new="1" data-phone="${digits.length <= 11 ? '55' + digits : digits}"><b>Usar o número ${esc(fmtPhone(digits.length <= 11 ? '55' + digits : digits))}</b><small>contato novo</small></li>`);
    }
    $('#sc-suggest').innerHTML = items.join('') || '<li class="meta">Nenhum contato. Digite o telefone com DDD.</li>';
    $('#sc-suggest').hidden = false;
  }, 250);
});
$('#sc-suggest').addEventListener('click', (ev) => {
  const li = ev.target.closest('li[data-phone]');
  if (!li) return;
  chooseContact(li.dataset.new ? { isNew: true, phone: li.dataset.phone } : { id: +li.dataset.id, name: li.dataset.name, phone: li.dataset.phone });
});
$('#sc-chosen').addEventListener('click', (ev) => { if (ev.target.id === 'sc-change') { chooseContact(null); $('#sc-contact').value = ''; $('#sc-contact').focus(); } });
$('#sc-quick').addEventListener('click', (ev) => { const b = ev.target.closest('[data-when]'); if (b) setWhen(b.dataset.when); });
$('#sc-form').elements.namedItem('body').addEventListener('input', (ev) => { $('#sc-count').textContent = `${ev.target.value.length}/4096`; });
$('#sc-form button[value=cancel]').addEventListener('click', (ev) => { ev.preventDefault(); $('#sc-dialog').close(); });

$('#sc-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = $('#sc-form').elements;
  const body = { body: f.namedItem('body').value, send_at: `${f.namedItem('date').value} ${f.namedItem('time').value}`, sign: f.namedItem('sign').checked };
  try {
    if (state.editing) {
      await api(`/api/schedules/${state.editing.id}`, { method: 'POST', body });
    } else {
      if (state.conv) body.conversation_id = state.conv.conversation.id;
      else {
        if (!state.contact) throw new Error('Escolha o contato ou digite um telefone.');
        if (state.contact.isNew) { body.phone = state.contact.phone; body.name = f.namedItem('name').value; } else body.contact_id = state.contact.id;
        body.sector_id = f.namedItem('sector_id').value;
      }
      await api('/api/schedules', { method: 'POST', body });
    }
    $('#sc-dialog').close();
    toast(state.editing ? 'Agendamento atualizado.' : `Agendado para ${f.namedItem('date').value.split('-').reverse().join('/')} às ${f.namedItem('time').value}.`);
    if (state.conv && new URLSearchParams(location.search).get('voltar')) { location.href = `/?c=${state.conv.conversation.id}`; return; }
    history.replaceState(null, '', '/agendamentos');
    await load();
  } catch (e) { $('#sc-error').textContent = e.message; $('#sc-error').hidden = false; }
});

$('#sc-list').addEventListener('click', async (ev) => {
  const b = ev.target.closest('[data-act]');
  if (!b) return;
  const it = state.items.find((x) => x.id == b.closest('[data-id]').dataset.id);
  if (b.dataset.act === 'edit') return openDialog(it);
  if (!confirm(`Cancelar a mensagem para ${it.contact_name || fmtPhone(it.phone)} de ${hm(it.send_at)}?`)) return;
  try { await api(`/api/schedules/${it.id}/cancel`, { method: 'POST' }); toast('Agendamento cancelado.'); await load(); } catch (e) { toast(e.message); }
});
document.querySelector('.t-tabs').addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-tab]'); if (!b) return;
  state.tab = b.dataset.tab;
  document.querySelectorAll('.t-tabs [data-tab]').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
  load();
});
$('#sc-new').addEventListener('click', () => openDialog());

(async () => {
  const me = await api('/api/me');
  state.me = me.user; state.sectors = me.sectors;
  await load();
  const c = new URLSearchParams(location.search).get('c');
  if (c) { try { openDialog(null, await api(`/api/conversations/${c}`)); } catch (e) { toast(e.message); } }
  setInterval(() => { if (!$('#sc-dialog').open) load(); }, 30000); // enviados saem da lista sozinhos
})();
