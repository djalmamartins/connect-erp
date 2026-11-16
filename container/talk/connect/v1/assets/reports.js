// Connect Talk — Painel (JS puro, sem build)
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const form = $('#rp-filters');
let filled = false;

const dur = (s) => {
  if (s === null || s === undefined) return '–';
  if (s < 60) return `${s} s`;
  const m = Math.round(s / 60);
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`;
};
const nota = (v) => (v === null || v === undefined ? '–' : String(v).replace('.', ','));
const dm = (d) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;
const weekday = (d) => ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][new Date(`${d}T12:00:00`).getDay()];
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function setPeriod(p) {
  const today = new Date();
  let from = new Date(today);
  if (p === '7') from.setDate(today.getDate() - 6);
  if (p === '30') from.setDate(today.getDate() - 29);
  if (p === 'month') from = new Date(today.getFullYear(), today.getMonth(), 1);
  $('#rp-from').value = iso(from);
  $('#rp-to').value = iso(today);
  form.querySelectorAll('[data-p]').forEach((b) => b.classList.toggle('on', b.dataset.p === p));
}

function query() {
  const q = new URLSearchParams();
  for (const [k, v] of new FormData(form)) if (v !== '') q.set(k, v);
  return q.toString();
}

async function load() {
  const q = query();
  $('#rp-export').href = `/api/reports/export.csv?${q}`;
  const res = await fetch(`/api/reports?${q}`);
  if (res.status === 401) { location.href = '/login'; return; }
  const d = await res.json();
  $('#rp-error').hidden = res.ok;
  if (!res.ok) { $('#rp-error').textContent = d.error || 'Erro ao carregar.'; return; }
  render(d);
}

function fillFilters(f, scope) {
  if (filled) return;
  filled = true;
  if (f.sectors.length > 1) {
    $('#rp-sector').insertAdjacentHTML('beforeend', f.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join(''));
    $('#rp-sector').hidden = false;
  }
  if (f.users.length) {
    $('#rp-user').insertAdjacentHTML('beforeend', f.users.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join(''));
    $('#rp-user').hidden = false;
  }
  if (scope === 'mine') $('#rp-sub').textContent = 'Os seus atendimentos: volume, tempo de espera, tempo de atendimento e avaliação dos clientes.';
  if (scope === 'sectors') $('#rp-sub').textContent = 'Os atendimentos dos setores que você gerencia: volume, tempos e avaliação dos clientes.';
}

function kpi(value, label, sub) {
  return `<div class="kpi"><div><b>${value}</b><span>${label}</span><small>${sub}</small></div></div>`;
}

function render(d) {
  fillFilters(d.filters, d.scope);
  const k = d.kpis;
  const answered = k.rating_asked ? Math.round((100 * k.rating_count) / k.rating_asked) : null;
  $('#rp-kpis').innerHTML = [
    kpi(k.total, 'Atendimentos', `${k.open} em andamento${k.unattended ? ` · ${k.unattended} encerrado(s) sem atendente` : ''}`),
    kpi(k.resolution_rate === null ? '–' : `${k.resolution_rate}%`, 'Encerrados', `${k.closed} de ${k.total}`),
    kpi(dur(k.avg_wait_seconds), 'Espera média', 'Da fila até alguém aceitar'),
    kpi(dur(k.avg_handle_seconds), 'Atendimento médio', 'De aceitar até encerrar'),
    kpi(k.rating_avg === null ? '–' : `${nota(k.rating_avg)} <em>/ 5</em>`, 'Nota dos clientes',
      k.rating_asked ? `${k.rating_count} de ${k.rating_asked} responderam (${answered}%)` : 'Ninguém avaliado no período'),
  ].join('');

  // por dia: uma série (atendimentos); o total encerrado aparece no balão
  const max = Math.max(1, ...d.by_day.map((x) => x.total));
  const many = d.by_day.length > 14;
  $('#rp-day-note').textContent = `${dm(d.period.from)} a ${dm(d.period.to)}`;
  $('#rp-days').innerHTML = d.by_day.map((x, i) => {
    const showLabel = !many || i % Math.ceil(d.by_day.length / 10) === 0 || i === d.by_day.length - 1;
    return `<div class="bar" tabindex="0" data-tip="${esc(`${weekday(x.date)} ${dm(x.date)}: ${x.total} atendimento(s), ${x.closed} encerrado(s)`)}">
      <span class="v">${!many && x.total ? x.total : ''}</span><i style="height:calc((100% - 43px) * ${x.total / max})"></i>
      <small>${showLabel ? (many ? dm(x.date) : `${weekday(x.date)}<br>${dm(x.date)}`) : ''}</small></div>`;
  }).join('');

  const hmax = Math.max(1, ...d.by_hour);
  $('#rp-hours').innerHTML = d.by_hour.map((n, h) => `<div class="bar" tabindex="0" data-tip="${h}h às ${h + 1}h: ${n} atendimento(s)">
      <span class="v"></span><i style="height:calc((100% - 43px) * ${n / hmax})"></i><small>${h % 3 === 0 ? `${h}h` : ''}</small></div>`).join('');

  const row = (x) => `<tr><td>${esc(x.name)}</td><td class="r">${x.total}</td><td class="r">${x.closed}</td>
    <td class="r">${dur(x.avg_wait_seconds)}</td><td class="r">${dur(x.avg_handle_seconds)}</td>
    <td class="r">${x.rating_avg === null ? '–' : `<span class="rp-star">★</span> ${nota(x.rating_avg)} <small class="meta">(${x.rating_count})</small>`}</td></tr>`;
  const empty = '<tr><td colspan="6" class="meta">Nenhum atendimento no período.</td></tr>';
  $('#rp-sectors').innerHTML = d.by_sector.map(row).join('') || empty;
  $('#rp-users').innerHTML = d.by_user.map(row).join('') || empty;

  const dist = d.ratings.distribution;
  const dtotal = Object.values(dist).reduce((a, b) => a + b, 0);
  const dmax = Math.max(1, ...Object.values(dist));
  $('#rp-rate-note').textContent = dtotal ? `${dtotal} avaliação(ões)` : '';
  $('#rp-dist').innerHTML = [5, 4, 3, 2, 1].map((s) => `<div class="rp-dist-row" data-tip="${dist[s]} cliente(s) deram nota ${s}">
      <span>${s} <span class="rp-star">★</span></span><div class="track"><i style="width:${(100 * dist[s]) / dmax}%"></i></div>
      <b>${dist[s]}</b><small>${dtotal ? Math.round((100 * dist[s]) / dtotal) : 0}%</small></div>`).join('');

  $('#rp-comments').innerHTML = d.ratings.latest.map((r) => `<li>
      <span class="rp-score s${r.score}">${r.score}</span>
      <div><b>${esc(r.contact)}</b> <span class="meta">· ${esc(r.user || '')} · ${esc(r.sector || '')} · ${dm(r.answered_at)}</span>
      ${r.comment ? `<p>${esc(r.comment)}</p>` : '<p class="meta">Sem comentário.</p>'}
      <a class="link" href="/?c=${r.conversation_id}">Protocolo ${esc(r.protocol)}</a></div></li>`).join('')
    || '<li class="meta">Ainda não há avaliações no período. A pergunta vai ao cliente quando o atendimento é encerrado.</li>';
}

// balão ao passar o mouse (ou focar com o teclado)
const tip = document.createElement('div');
tip.className = 'rp-tip';
tip.hidden = true;
document.body.append(tip);
const showTip = (el) => {
  tip.textContent = el.dataset.tip;
  tip.hidden = false;
  const r = (el.querySelector('i') || el).getBoundingClientRect();
  tip.style.left = `${Math.min(window.innerWidth - tip.offsetWidth - 8, Math.max(8, r.left + r.width / 2 - tip.offsetWidth / 2))}px`;
  tip.style.top = `${r.top + window.scrollY - tip.offsetHeight - 8}px`;
};
document.addEventListener('mouseover', (e) => { const el = e.target.closest('[data-tip]'); if (el) showTip(el); else tip.hidden = true; });
document.addEventListener('focusin', (e) => { const el = e.target.closest('[data-tip]'); if (el) showTip(el); });
document.addEventListener('focusout', () => { tip.hidden = true; });

form.addEventListener('click', (e) => {
  const b = e.target.closest('[data-p]');
  if (!b) return;
  setPeriod(b.dataset.p);
  load();
});
form.addEventListener('change', (e) => {
  if (e.target.type === 'date') form.querySelectorAll('[data-p]').forEach((b) => b.classList.remove('on'));
  load();
});
form.addEventListener('submit', (e) => e.preventDefault());

setPeriod('7');
load();
