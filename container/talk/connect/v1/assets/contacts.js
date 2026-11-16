// Connect Talk — Contatos (JS puro, sem build)
const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const state = { q: '', tag: '', status: '', page: 1, tags: [], current: null };
const relLabel = { owner: 'Proprietário', tenant: 'Inquilino', resident: 'Morador', financial: 'Responsável financeiro' };
const convStatus = { bot: 'Triagem', pending: 'Na fila', open: 'Em atendimento', closed: 'Encerrado' };
const fmtPhone = (p) => { const m = String(p).match(/^55(\d{2})(\d{4,5})(\d{4})$/); return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p; };
const initials = (n) => (n || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const dt = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const snap = (c) => { try { return JSON.parse(c.erp_snapshot || 'null') || {}; } catch { return {}; } };
const chip = (t) => `<span class="chip" style="--c:${esc(t.color)}">${esc(t.name)}</span>`;

async function api(path, opts = {}) {
  const res = await fetch(path, { method: opts.method || 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: opts.body ? JSON.stringify(opts.body) : undefined });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) throw new Error(data.error || 'Erro');
  return data;
}

async function load() {
  const qs = new URLSearchParams({ q: state.q, tag: state.tag, status: state.status, page: state.page });
  const r = await api(`/api/contacts?${qs}`);
  const pages = Math.max(1, Math.ceil(r.total / r.per_page));
  $('#c-rows').innerHTML = r.items.map((c) => {
    const d = snap(c);
    const tags = (c.tag_ids || '').split(',').filter(Boolean).map((id) => state.tags.find((t) => t.id == id)).filter(Boolean).map(chip).join('');
    return `<tr data-id="${c.id}" class="${state.current == c.id ? 'sel' : ''}${c.blocked_at ? ' blocked' : ''}">
      <td><div class="who"><span class="avatar sm">${c.name ? esc(initials(c.name)) : '?'}</span><div><b>${esc(c.name || 'Sem nome')}</b><small>${esc(fmtPhone(c.phone))}</small></div>
        ${c.blocked_at ? '<span class="badge-block">Bloqueado</span>' : ''}</div></td>
      <td>${d.condominio ? `${esc(d.condominio)}<small class="sub">${esc(d.unidade || '')}${d.vinculo ? ' · ' + esc(relLabel[d.vinculo] || d.vinculo) : ''}</small>` : '<span class="meta">Não identificado no ERP</span>'}</td>
      <td><div class="chips">${tags}</div></td>
      <td>${c.conversations}</td>
      <td>${dt(c.last_contact_at)}</td></tr>`;
  }).join('') || '<tr><td colspan="5" class="empty-row">Nenhum contato encontrado.</td></tr>';
  $('#c-count').textContent = `${r.total} contato(s)`;
  $('#c-page').textContent = `${r.page} de ${pages}`;
  $('#c-prev').disabled = r.page <= 1; $('#c-next').disabled = r.page >= pages;
}

async function show(id) {
  state.current = id;
  document.querySelectorAll('#c-rows tr').forEach((tr) => tr.classList.toggle('sel', tr.dataset.id == id));
  const c = await api(`/api/contacts/${id}`);
  const d = snap(c);
  const info = [['Telefone', fmtPhone(c.phone)], ['Condomínio', d.condominio], ['Unidade', d.unidade], ['Vínculo', relLabel[d.vinculo] || d.vinculo], ['E-mail', d.email], ['Desde', dt(c.created_at)]].filter(([, v]) => v);
  $('#c-detail').innerHTML = `
    <div class="cp-head"><span class="avatar">${esc(initials(c.name))}</span>
      <div><b id="c-name">${esc(c.name || 'Sem nome')}</b>
      ${c.erp_person_id ? `<span class="badge-ok">${esc(relLabel[d.vinculo] || 'Cadastro no ERP')}</span>` : '<span class="badge-off">Não identificado no ERP</span>'}</div>
      <button type="button" class="link" id="c-rename">Editar nome</button></div>
    ${c.blocked_at ? `<div class="blocked-box"><b>Contato bloqueado</b> em ${dt(c.blocked_at)}${c.block_reason ? `: ${esc(c.block_reason)}` : ''}.<br>
      ${c.blocked_messages} mensagem(ns) descartada(s) desde então.</div>` : ''}
    <section><h4>Informações</h4><dl>${info.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>
      ${c.tags.length ? `<div class="chips" style="margin-top:8px">${c.tags.map(chip).join('')}</div>` : ''}</section>
    <section><h4>Atendimentos</h4>${c.history.length ? `<ul class="cp-hist">${c.history.map((h) => `
      <li data-open="${h.id}"><b>${esc(h.protocol)}</b><span>${esc(h.sector_name || '—')} · ${esc(h.subject || '—')}</span>
      <small class="st st-${h.status}">${convStatus[h.status]}</small><time>${dt(h.opened_at).slice(0, 8)}</time></li>`).join('')}</ul>` : '<p class="meta">Nenhum atendimento que você possa ver.</p>'}</section>
    ${c.notes.length ? `<section><h4>Notas</h4><ul class="cp-notes">${c.notes.map((n) => `<li><p>${esc(n.body)}</p><small>${esc((n.user_name || '').split(' (')[0])} · ${dt(n.created_at)}</small></li>`).join('')}</ul></section>` : ''}
    ${c.can_block ? `<section class="c-actions">${c.blocked_at
      ? '<button type="button" class="ghost" id="c-unblock">Desbloquear contato</button>'
      : '<button type="button" class="ghost danger" id="c-block">Bloquear contato</button>'}</section>` : ''}`;
}

document.addEventListener('click', async (e) => {
  const tr = e.target.closest('#c-rows tr[data-id]');
  if (tr) { show(tr.dataset.id).catch((err) => alert(err.message)); return; }
  const open = e.target.closest('[data-open]');
  if (open) { location.href = `/?c=${open.dataset.open}`; return; }
  try {
    if (e.target.id === 'c-rename') {
      const name = prompt('Nome do contato:', $('#c-name').textContent);
      if (name && name.trim()) { await api(`/api/contacts/${state.current}/rename`, { method: 'POST', body: { name } }); await show(state.current); load(); }
    }
    if (e.target.id === 'c-block') { $('#block-reason').value = ''; $('#block-dialog').showModal(); }
    if (e.target.id === 'c-unblock') { await api(`/api/contacts/${state.current}/unblock`, { method: 'POST' }); await show(state.current); load(); }
    if (e.target.id === 'c-prev') { state.page--; load(); }
    if (e.target.id === 'c-next') { state.page++; load(); }
  } catch (err) { alert(err.message); }
});
$('#block-dialog').addEventListener('close', async () => {
  if ($('#block-dialog').returnValue !== 'ok') return;
  try { await api(`/api/contacts/${state.current}/block`, { method: 'POST', body: { reason: $('#block-reason').value } }); await show(state.current); load(); }
  catch (err) { alert(err.message); }
});
let t;
$('#c-q').addEventListener('input', (e) => { clearTimeout(t); t = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; load(); }, 250); });
$('#c-tag').addEventListener('change', (e) => { state.tag = e.target.value; state.page = 1; load(); });
$('#c-status').addEventListener('change', (e) => { state.status = e.target.value; state.page = 1; load(); });

(async function init() {
  state.tags = (await api('/api/tags').catch(() => [])).map((x) => ({ ...x, id: Number(x.id) }));
  $('#c-tag').innerHTML += state.tags.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('');
  const me = await api('/api/me');
  if (!(me.user.supervisor || (me.user.manager_sectors || []).length)) $('#nav-tags')?.remove();
  $('#live').textContent = '● Online'; $('#live').classList.add('on');
  await load();
  const first = new URLSearchParams(location.search).get('id');
  if (first) show(first);
})();
