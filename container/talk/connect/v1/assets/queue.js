// Connect Talk — Fila de atendimento (JS puro, sem build)
const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const SECTOR_COLORS = ['#12804A', '#2F6FDE', '#C9A227', '#7A3EC8', '#C2410C', '#0E7490'];
const PRIO = { high: 'Alta prioridade', normal: 'Prioridade normal', low: 'Baixa prioridade' };
const PRIO_RANK = { high: 0, normal: 1, low: 2 };
const state = { data: null, sector: '', order: 'oldest', search: '', loadedAt: 0, tags: [], menuFor: null };

async function api(path, opts = {}) {
  const res = await fetch(path, { method: opts.method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: opts.body ? JSON.stringify(opts.body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(data.error || 'Erro');
  return data;
}
const mmss = (s) => { s = Math.max(0, Math.round(s)); const h = Math.floor(s / 3600); const m = Math.floor((s % 3600) / 60); const r = s % 60;
  return h ? `${h}h${String(m).padStart(2, '0')}` : `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`; };
const hhmm = (d) => (d ? d.slice(11, 16) : '');
const initials = (n) => (n || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const fmtPhone = (p) => { const m = String(p).match(/^55(\d{2})(\d{4,5})(\d{4})$/); return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p; };
const elapsed = () => (Date.now() - state.loadedAt) / 1000; // o relógio anda entre uma atualização e outra
const level = (s) => { const t = state.data.thresholds; return s >= t.late_minutes * 60 ? 'late' : s >= t.warn_minutes * 60 ? 'warn' : 'ok'; };
const sectorColor = (id) => SECTOR_COLORS[state.data.distribution.findIndex((d) => d.sector_id == id) % SECTOR_COLORS.length] || '#5F6B66';

async function load() {
  const [d, tags] = await Promise.all([api('/api/queue'), state.tags.length ? state.tags : api('/api/tags').catch(() => [])]);
  state.data = d; state.tags = tags; state.loadedAt = Date.now();
  render();
}

function rows() {
  let list = state.data.waiting.filter((w) => !state.sector || w.sector_id == state.sector);
  const q = state.search.toLowerCase();
  if (q) list = list.filter((w) => `${w.contact_name} ${w.phone} ${w.subject} ${w.protocol}`.toLowerCase().includes(q));
  if (state.order === 'priority') list = [...list].sort((a, b) => PRIO_RANK[a.priority] - PRIO_RANK[b.priority] || b.wait_seconds - a.wait_seconds);
  if (state.order === 'newest') list = [...list].reverse();
  return list;
}

function render() {
  const d = state.data; const s = d.stats;
  $('#k-waiting').textContent = s.waiting;
  const tick = () => (s.ticking ? elapsed() : 0);
  $('#k-avg').textContent = mmss(s.waiting ? s.avg_wait_seconds + tick() : 0);
  $('#k-today').textContent = s.today_total;
  $('#k-rate').textContent = s.resolution_rate === null ? '–' : `${s.resolution_rate}%`;
  $('#k-rate-sub').textContent = `${s.today_closed} de ${s.today_total} encerradas hoje`;
  $('#t-warn').textContent = d.thresholds.warn_minutes; $('#t-late').textContent = d.thresholds.late_minutes;
  const badge = $('#badge-queue'); if (badge) badge.textContent = s.waiting || '';

  // abas por setor
  const color = (id) => (state.sectors || []).find((x) => x.id == id)?.color;
  const tabs = [{ id: '', name: 'Todos', n: s.waiting }, ...d.distribution.map((x) => ({ id: x.sector_id, name: x.name, n: x.waiting, c: x.color || color(x.sector_id) }))];
  $('#q-tabs').innerHTML = tabs.map((t) => `<button type="button" role="tab" aria-selected="${state.sector == t.id}" data-sector="${t.id}" class="${state.sector == t.id ? 'active' : ''}">${t.id !== '' ? `<i class="dot" style="--c:${esc(t.c || '#6a756f')}"></i>` : ''}${esc(t.name)}<span class="n${t.n ? ' has' : ''}">${t.n}</span></button>`).join('');

  const list = rows();
  const tagName = (id) => state.tags.find((t) => t.id == id);
  $('#q-rows').innerHTML = list.map((w) => {
    const wait = w.wait_seconds + (w.paused ? 0 : elapsed());
    const tags = (w.tag_ids || '').split(',').filter(Boolean).map(tagName).filter(Boolean)
      .map((t) => `<span class="chip" style="--c:${esc(t.color)}">${esc(t.name)}</span>`).join('');
    return `<tr data-id="${w.id}">
      <td><span class="pos">${w.position}</span></td>
      <td><div class="who"><span class="avatar sm" style="background:${sectorColor(w.sector_id)}">${esc(initials(w.contact_name || w.phone))}</span>
        <div><b>${esc(w.contact_name || fmtPhone(w.phone))}</b><small>${esc(fmtPhone(w.phone))}</small>${tags ? `<div class="chips">${tags}</div>` : ''}</div></div></td>
      <td><span class="sec"><i style="background:${sectorColor(w.sector_id)}"></i>${esc(w.sector_name || '—')}</span></td>
      <td class="subj"><b>${esc(w.protocol)}</b><small>${esc(w.subject || '—')}</small></td>
      <td><b class="wait ${level(wait)}"${w.paused ? '' : ` data-wait="${w.wait_seconds}"`}>${mmss(wait)}</b>${w.paused ? '<small class="paused" title="Fora do horário do setor a espera não conta">⏸ fora do horário</small>' : ''}<small class="prio prio-${w.priority}">${PRIO[w.priority]}</small><small class="since">na fila desde ${hhmm(w.queued_at)}</small></td>
      <td class="r"><button type="button" class="ghost sm" data-accept="${w.id}">Atender</button><button type="button" class="dots" data-menu="${w.id}" aria-label="Mais ações">⋮</button></td>
    </tr>`;
  }).join('') || `<tr><td colspan="6" class="empty-row">${s.waiting ? 'Ninguém com esse filtro.' : 'Ninguém esperando. 🎉'}</td></tr>`;
  $('#q-foot').textContent = `Mostrando ${list.length} de ${s.waiting} na fila`;

  // distribuição (rosca)
  const total = d.distribution.reduce((a, x) => a + x.waiting, 0);
  let acc = 0; const R = 46; const C = 2 * Math.PI * R;
  const arcs = total ? d.distribution.filter((x) => x.waiting).map((x) => {
    const len = (x.waiting / total) * C; const seg = `<circle r="${R}" cx="60" cy="60" fill="none" stroke="${sectorColor(x.sector_id)}" stroke-width="16" stroke-dasharray="${len} ${C - len}" stroke-dashoffset="${-acc}" transform="rotate(-90 60 60)"/>`;
    acc += len; return seg;
  }).join('') : '';
  $('#q-donut').innerHTML = `<svg viewBox="0 0 120 120" role="img" aria-label="Distribuição da fila por setor"><circle r="${R}" cx="60" cy="60" fill="none" stroke="#eef1ef" stroke-width="16"/>${arcs}
    <text x="60" y="58" text-anchor="middle" class="d-n">${total}</text><text x="60" y="74" text-anchor="middle" class="d-l">Total</text></svg>`;
  $('#q-dist').innerHTML = d.distribution.map((x) => `<li><i style="background:${sectorColor(x.sector_id)}"></i>${esc(x.name)}<b>${x.waiting}</b><span>${total ? Math.round((100 * x.waiting) / total) : 0}%</span></li>`).join('');

  // atendentes
  const online = d.attendants.filter((a) => a.online).length;
  $('#q-online').textContent = `${online} online`;
  const st = { available: 'Disponível', busy: 'Atendendo', offline: 'Offline' };
  $('#q-people').innerHTML = d.attendants.map((a) => `<li class="${a.status}"><span class="avatar sm">${esc(initials(a.name.replace(/\(.*\)/, '')))}<i></i></span>
    <div><b>${esc(a.name.replace(/\s*\(.*\)/, ''))}</b><small>${st[a.status]}${a.open_count ? ` · ${a.open_count} em atendimento` : ''}${a.sectors ? ` · ${esc(a.sectors)}` : ''}</small></div></li>`).join('')
    || '<li class="meta">Nenhum atendente cadastrado.</li>';

  $('#w-max').textContent = s.waiting ? mmss(s.max_wait_seconds + tick()) : '–';
  $('#w-avg').textContent = s.waiting ? mmss(s.avg_wait_seconds + tick()) : '–';
  $('#w-min').textContent = s.waiting ? mmss(s.min_wait_seconds + tick()) : '–';
  $('#q-updated').textContent = `Atualizado às ${new Date(state.loadedAt).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
}

// o tempo de espera corre a cada segundo, sem ir ao servidor
setInterval(() => {
  if (!state.data) return;
  document.querySelectorAll('.wait[data-wait]').forEach((el) => {
    const s = Number(el.dataset.wait) + elapsed(); el.textContent = mmss(s); el.className = `wait ${level(s)}`;
  });
  const s = state.data.stats;
  if (s.waiting && s.ticking) {
    $('#k-avg').textContent = mmss(s.avg_wait_seconds + elapsed());
    $('#w-max').textContent = mmss(s.max_wait_seconds + elapsed()); $('#w-avg').textContent = mmss(s.avg_wait_seconds + elapsed()); $('#w-min').textContent = mmss(s.min_wait_seconds + elapsed());
  }
}, 1000);
setInterval(() => load().catch(() => {}), 30000); // presença e números do dia

document.addEventListener('click', async (e) => {
  const tab = e.target.closest('[data-sector]');
  if (tab) { state.sector = tab.dataset.sector; render(); return; }
  if (e.target.closest('#q-refresh')) { load(); return; }
  const acc = e.target.dataset.accept;
  if (acc) {
    try { await api(`/api/conversations/${acc}/accept`, { method: 'POST' }); location.href = `/?c=${acc}`; }
    catch (err) { alert(err.message); load(); }
    return;
  }
  const menu = $('#q-menu');
  const m = e.target.dataset.menu;
  if (m) {
    const r = e.target.getBoundingClientRect();
    state.menuFor = m; menu.hidden = false;
    menu.style.top = `${r.bottom + 4 + window.scrollY}px`; menu.style.left = `${Math.min(r.right - 190, window.innerWidth - 200)}px`;
    return;
  }
  if (e.target.dataset.prio && state.menuFor) {
    menu.hidden = true;
    try { await api(`/api/conversations/${state.menuFor}/priority`, { method: 'POST', body: { priority: e.target.dataset.prio } }); load(); }
    catch (err) { alert(err.message); }
    return;
  }
  if (e.target.hasAttribute('data-open') && state.menuFor) { location.href = `/?c=${state.menuFor}`; return; }
  if (!e.target.closest('#q-menu')) menu.hidden = true;
});
$('#q-order').addEventListener('change', (e) => { state.order = e.target.value; render(); });
$('#q-search').addEventListener('input', (e) => { state.search = e.target.value.trim(); render(); });

// tempo real: qualquer mudança nas conversas atualiza a fila (com folga para juntar eventos)
let timer;
function connect() {
  const es = new EventSource('/events');
  es.onopen = () => { $('#live').textContent = '● Online'; $('#live').classList.add('on'); };
  es.onerror = () => { $('#live').dataset.sse = '1'; $('#live').textContent = '● reconectando'; $('#live').classList.remove('on'); };
  ['conversation.created', 'conversation.updated', 'conversation.transferred', 'message.created'].forEach((t) =>
    es.addEventListener(t, () => { clearTimeout(timer); timer = setTimeout(() => load().catch(() => {}), 400); }));
}
load().catch((err) => { $('#q-rows').innerHTML = `<tr><td colspan="6">${esc(err.message)}</td></tr>`; });
connect();
