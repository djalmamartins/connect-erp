// Connect Talk — Horário de atendimento (JS puro, sem build)
const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const DAYS = [[1, 'Segunda'], [2, 'Terça'], [3, 'Quarta'], [4, 'Quinta'], [5, 'Sexta'], [6, 'Sábado'], [0, 'Domingo']];
const toast = (m) => { const t = document.createElement('div'); t.className = 'toast'; t.textContent = m; document.body.append(t); setTimeout(() => t.remove(), 3500); };
let data = null;
let dirty = false;

async function api(path, body) {
  const res = await fetch(path, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: body ? JSON.stringify(body) : undefined });
  const out = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(out.error || 'Erro');
  return out;
}

// ------------------------------------------------------------------ editor de semana
function weekEditor(week, key) {
  return `<div class="hr-week-grid" data-week="${key}">${DAYS.map(([d, label]) => {
    const iv = week?.[d] || [];
    return `<div class="hr-day${iv.length ? '' : ' closed'}" data-day="${d}">
      <label class="hr-day-name"><input type="checkbox" data-open ${iv.length ? 'checked' : ''}> ${label}</label>
      <div class="hr-ivs">${iv.map((p) => ivRow(p)).join('')}${iv.length ? '' : '<span class="meta">Fechado</span>'}</div>
      <button type="button" class="link" data-add ${iv.length && iv.length < 3 ? '' : 'hidden'}>+ faixa</button></div>`;
  }).join('')}</div>`;
}
const ivRow = ([a, b]) => `<span class="hr-iv"><input type="time" value="${esc(a)}" aria-label="Das"> às <input type="time" value="${esc(b === '24:00' ? '23:59' : b)}" aria-label="Até"><button type="button" class="x" data-del aria-label="Tirar faixa">×</button></span>`;

function readWeek(grid) {
  const w = {};
  grid.querySelectorAll('.hr-day').forEach((day) => {
    w[day.dataset.day] = [...day.querySelectorAll('.hr-iv')].map((iv) => {
      const [a, b] = iv.querySelectorAll('input');
      return [a.value, b.value === '23:59' ? '24:00' : b.value];
    });
  });
  return w;
}

function refreshDay(day) {
  const n = day.querySelectorAll('.hr-iv').length;
  day.classList.toggle('closed', n === 0);
  day.querySelector('[data-open]').checked = n > 0;
  day.querySelector('[data-add]').hidden = n === 0 || n >= 3;
  const meta = day.querySelector('.hr-ivs .meta');
  if (n === 0 && !meta) day.querySelector('.hr-ivs').insertAdjacentHTML('beforeend', '<span class="meta">Fechado</span>');
  if (n > 0 && meta) meta.remove();
}

document.addEventListener('change', (e) => {
  const day = e.target.closest('.hr-day');
  if (day && e.target.matches('[data-open]')) {
    const ivs = day.querySelector('.hr-ivs');
    ivs.innerHTML = e.target.checked ? ivRow(['08:00', '18:00']) : '';
    refreshDay(day);
  }
  const mode = e.target.closest('[data-mode]');
  if (mode) {
    const box = mode.closest('.hr-sector').querySelector('.hr-own');
    box.hidden = mode.value !== 'custom';
    if (mode.value === 'custom' && !box.innerHTML.trim()) box.innerHTML = weekEditor(readWeek($('#hr-week .hr-week-grid')), 'own');
  }
  if (e.target.closest('.hours')) markDirty();
});
document.addEventListener('click', (e) => {
  const day = e.target.closest('.hr-day');
  if (day && e.target.matches('[data-add]')) {
    const last = [...day.querySelectorAll('.hr-iv input')].pop()?.value || '12:00';
    day.querySelector('.hr-ivs').insertAdjacentHTML('beforeend', ivRow([last, last < '18:00' ? '18:00' : '23:59']));
    refreshDay(day);
    markDirty();
  }
  if (day && e.target.matches('[data-del]')) {
    e.target.closest('.hr-iv').remove();
    refreshDay(day);
    markDirty();
  }
});

// ------------------------------------------------------------------ tela
function render() {
  const c = data.config;
  $('#hr-enabled').checked = c.enabled;
  $('#hr-enabled-label').textContent = c.enabled ? 'Ligado' : 'Desligado';
  $('#hr-clock').textContent = `${data.now.slice(8, 10)}/${data.now.slice(5, 7)}, ${data.now.slice(11, 16)}`;
  $('#hr-status').innerHTML = data.sectors.map((s) => `<li><i style="background:${esc(s.color || '#5F6B66')}"></i><b>${esc(s.name)}</b>
    ${!c.enabled ? '<span class="pill">sem controle de horário</span>' : s.open_now ? '<span class="pill ok">atendendo</span>' : `<span class="pill off">fechado · volta ${esc(s.next_label || '–')}</span>`}
    <small class="meta">${s.mode === 'always' ? '24 horas' : esc(s.summary)}</small></li>`).join('');
  $('#hr-week').innerHTML = weekEditor(c.week, 'default');
  $('#hr-sectors').innerHTML = data.sectors.map((s) => `<div class="hr-sector" data-sector="${s.id}">
    <div class="hr-sector-head"><i style="background:${esc(s.color || '#5F6B66')}"></i><b>${esc(s.name)}</b>
      <select data-mode aria-label="Horário do setor ${esc(s.name)}">
        <option value="default"${s.mode === 'default' ? ' selected' : ''}>Segue o horário geral</option>
        <option value="custom"${s.mode === 'custom' ? ' selected' : ''}>Horário próprio</option>
        <option value="always"${s.mode === 'always' ? ' selected' : ''}>Atende 24 horas</option></select></div>
    <div class="hr-own"${s.mode === 'custom' ? '' : ' hidden'}>${s.mode === 'custom' ? weekEditor(s.week, 'own') : ''}</div></div>`).join('');
  $('#hr-message').value = c.message;
  $('#hr-vars').innerHTML = Object.entries(data.variables).map(([k, v]) => `<button type="button" class="chip-btn" data-var="${k}" title="${esc(v)}">${k}</button>`).join('');
  preview();
  renderHolidays();
  dirty = false;
  $('#hr-save').disabled = true;
}

function renderHolidays() {
  const year = Number(data.now.slice(0, 4));
  const nextYear = Number(data.now.slice(5, 7)) >= 10 ? year + 1 : year;
  $('#hr-import').textContent = `Incluir feriados nacionais de ${nextYear}`;
  $('#hr-import').dataset.year = nextYear;
  $('#hr-holidays').innerHTML = data.holidays.map((h) => `<li><time>${h.day.slice(8, 10)}/${h.day.slice(5, 7)}/${h.day.slice(0, 4)}</time>
    <span>${esc(h.name)} <small class="meta">${esc(h.weekday)}</small></span><button type="button" class="x" data-hol="${h.id}" aria-label="Remover ${esc(h.name)}">×</button></li>`).join('')
    || '<li class="meta">Nenhum feriado cadastrado.</li>';
}

function preview() {
  let t = $('#hr-message').value;
  for (const [k, v] of Object.entries(data.sample)) t = t.split(k).join(v);
  $('#hr-preview').innerHTML = esc(t).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
}

function markDirty() {
  dirty = true;
  $('#hr-save').disabled = false;
}

function collect() {
  const sectors = {};
  document.querySelectorAll('.hr-sector').forEach((s) => {
    const mode = s.querySelector('[data-mode]').value;
    sectors[s.dataset.sector] = mode === 'custom' ? { mode, week: readWeek(s.querySelector('.hr-week-grid')) } : { mode };
  });
  return { enabled: $('#hr-enabled').checked, week: readWeek($('#hr-week .hr-week-grid')), sectors, message: $('#hr-message').value };
}

async function save() {
  $('#hr-error').hidden = true;
  try {
    data = await api('/api/settings/hours', collect());
    render();
    toast('Horário salvo.');
  } catch (e) {
    $('#hr-error').textContent = e.message;
    $('#hr-error').hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

$('#hr-save').addEventListener('click', save);
$('#hr-enabled').addEventListener('change', (e) => { $('#hr-enabled-label').textContent = e.target.checked ? 'Ligado' : 'Desligado'; markDirty(); });
$('#hr-message').addEventListener('input', () => { preview(); markDirty(); });
$('#hr-vars').addEventListener('click', (e) => {
  const b = e.target.closest('[data-var]');
  if (!b) return;
  const ta = $('#hr-message');
  const at = ta.selectionStart ?? ta.value.length;
  ta.value = ta.value.slice(0, at) + b.dataset.var + ta.value.slice(ta.selectionEnd ?? at);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = at + b.dataset.var.length;
  preview();
  markDirty();
});
$('#hr-copy').addEventListener('click', () => {
  const grid = $('#hr-week .hr-week-grid');
  const mon = grid.querySelector('[data-day="1"] .hr-ivs').innerHTML;
  for (const d of [2, 3, 4, 5]) {
    const day = grid.querySelector(`[data-day="${d}"]`);
    day.querySelector('.hr-ivs').innerHTML = mon;
    // innerHTML não copia o valor digitado: copia campo a campo
    const src = [...grid.querySelectorAll('[data-day="1"] .hr-iv input')].map((i) => i.value);
    day.querySelectorAll('.hr-iv input').forEach((i, n) => { i.value = src[n]; });
    refreshDay(day);
  }
  markDirty();
});
$('#hr-add').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  try {
    data.holidays = await api('/api/settings/holidays', { day: f.elements.namedItem('day').value, name: f.elements.namedItem('name').value });
    f.reset();
    renderHolidays();
  } catch (err) { toast(err.message); }
});
$('#hr-holidays').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-hol]');
  if (!b) return;
  try { data.holidays = await api(`/api/settings/holidays/${b.dataset.hol}/delete`, {}); renderHolidays(); } catch (err) { toast(err.message); }
});
$('#hr-import').addEventListener('click', async (e) => {
  try {
    const r = await api('/api/settings/holidays/import', { year: Number(e.target.dataset.year) });
    data.holidays = r.holidays;
    renderHolidays();
    toast(r.added ? `${r.added} feriado(s) incluído(s).` : 'Os feriados nacionais desse ano já estavam na lista.');
  } catch (err) { toast(err.message); }
});
window.addEventListener('beforeunload', (e) => { if (dirty) e.preventDefault(); });

api('/api/settings/hours').then((d) => { data = d; render(); }).catch((e) => { $('#hr-error').textContent = e.message; $('#hr-error').hidden = false; });
