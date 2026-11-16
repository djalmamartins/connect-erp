// Connect Talk — caixa de entrada (JS puro, sem build)
const csrf = document.querySelector('meta[name=csrf]').content;
const isLab = document.body.dataset.lab === '1';
const $ = (s, el = document) => el.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hhmm = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');

const state = { tab: 'pending', sector: '', current: null, me: null, sectors: [], users: {}, search: '' };

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) location.href = '/login';
  if (!res.ok) { const e = new Error(data.error || 'Erro'); e.status = res.status; throw e; }
  return data;
}
const toast = (msg) => alert(msg);
const statusLabel = { bot: 'no menu', pending: 'na fila', open: 'em atendimento', closed: 'encerrado' };
const stageLabel = { waiting_customer: 'aguardando cliente', waiting_internal: 'aguardando retorno interno' };
const statusText = (c) => (c.status === 'open' && stageLabel[c.stage]) || statusLabel[c.status];
// seletor de status da conversa: o valor atual junta status + situação (aguardando…)
function statusSelect(c) {
  const cur = c.status === 'open' && c.stage ? c.stage : c.status;
  const opts = c.status === 'closed'
    ? [['closed', 'Encerrado'], ['open', 'Reabrir (em atendimento)']]
    : [['pending', 'Na fila'], ['open', 'Em atendimento'], ['waiting_customer', 'Aguardando cliente'], ['waiting_internal', 'Aguardando retorno interno'], ['closed', 'Encerrado']];
  return `<label class="status-pick st-${cur}" title="Mudar o status do atendimento"><span>Status</span><select id="status-select" data-cur="${cur}">${opts.map(([v, l]) => `<option value="${v}"${v === cur ? ' selected' : ''}>${l}</option>`).join('')}</select></label>`;
}
const sectorName = (id) => state.sectors.find((s) => s.id == id)?.name || '—';

// ------------------------------------------------------------ lista
async function loadList() {
  if (state.search) return runSearch();
  searchBar();
  const rows = await api(`/api/conversations?tab=${state.tab}&sector=${state.sector}&tag=${state.tagFilter || ''}`);
  $('#conversations').innerHTML = rows.map((c) => `
    <li data-id="${c.id}" class="${state.current == c.id ? 'active' : ''}">
      <span class="name">${esc(c.contact_name || c.phone)}</span>
      ${c.unread > 0 && c.status !== 'closed' ? `<span class="unread">${c.unread}</span>` : `<span class="meta">${hhmm(c.last_message_at)}</span>`}
      <span class="meta"><span class="tag">${esc(c.sector_name || 'menu')}</span> ${esc(c.protocol)}</span><span></span>
      <span class="last">${c.status === 'open' && c.stage ? `<span class="stage st-${c.stage}">${c.stage === 'waiting_customer' ? 'Aguardando cliente' : 'Aguardando retorno'}</span> ` : ''}${esc(waPlain(c.last_body))}</span>
      ${c.tag_ids ? `<span class="chips">${tagChips(c.tag_ids.split(',').map(Number))}</span>` : ''}
    </li>`).join('') || '<li class="meta">Nada por aqui.</li>';
  if (state.tab === 'pending') { $('#badge-pending').textContent = rows.length || ''; $('#badge-nav').textContent = rows.length || ''; if ($('#badge-queue')) $('#badge-queue').textContent = rows.length || ''; }
}

function searchBar() {
  const bar = $('#search-on');
  bar.hidden = !state.search;
  bar.innerHTML = state.search ? `Resultados para <b>${esc(state.search)}</b> <button type="button" class="link" id="search-clear">Limpar busca</button>` : '';
}
async function runSearch() {
  searchBar();
  const rows = await api(`/api/search?q=${encodeURIComponent(state.search)}&contact=${encodeURIComponent(/\d{6,}|^\d{8}-/.test(state.search) ? state.search : '')}`);
  $('#conversations').innerHTML = rows.map((r) => `
    <li data-id="${r.conversation_id}" class="search-result">
      <span class="name">${esc(r.contact_name || r.phone)}</span><span class="meta">${hhmm(r.created_at)}</span>
      <span class="last"><span class="dir">${r.direction === 'in' ? '⬅ chegou' : '➡ saiu'}</span> ${esc(r.body)}</span>
    </li>`).join('') || '<li class="meta">Nenhuma mensagem encontrada.</li>';
}

// ------------------------------------------------------------ conversa
async function openConversation(id) {
  // atualização em tempo real da mesma conversa: guarda o rascunho e o arquivo escolhido
  const same = state.current == id && $('#reply');
  const draft = same ? { text: $('#reply').value, focus: document.activeElement === $('#reply'), pos: $('#reply').selectionStart } : null;
  if (!same) state.attach = null;
  state.current = id;
  document.querySelector('.layout').classList.add('has-chat');
  const { conversation: c, contact, messages, can_act } = await api(`/api/conversations/${id}`);
  const actions = [];
  if (can_act && c.status !== 'bot') actions.push(statusSelect(c));
  if (can_act && c.status === 'pending') actions.push('<button class="primary" data-act="accept">Aceitar</button>');
  if (can_act && c.status !== 'closed' && c.status !== 'bot') actions.push('<button data-act="transfer">Transferir</button>', '<button data-act="close">Encerrar</button>');
  if (can_act && c.status !== 'bot' && !c.helpdesk_ticket_id) actions.push('<button data-act="ticket" title="Abrir o chamado no Help Desk do Connect ERP">Abrir chamado</button>');
  if (can_act && c.status !== 'closed') actions.push('<button data-act="note">Nota interna</button>');
  if (can_act && c.status !== 'bot') actions.push('<button data-act="schedule" title="Marcar uma mensagem para outro dia e hora">Agendar</button>');
  $('#chat').innerHTML = `
    <div class="chat-head">
      <div><div class="who">${esc(contact.name || contact.phone)}</div>
      <div class="meta">${esc(contact.phone)} · ${c.helpdesk_protocol ? `chamado <b>${esc(c.helpdesk_protocol)}</b>` : `protocolo <b>${esc(c.protocol)}</b>`} · ${esc(sectorName(c.sector_id))} · ${statusText(c)}
      ${c.assigned_user_id ? ' · ' + esc(state.users[c.assigned_user_id] || '#' + c.assigned_user_id) : ''}</div></div>
      <div class="actions">${actions.join('')}</div>
    </div>
    <div class="msgs" id="msgs">${messages.map(renderMsg).join('')}</div>
    ${can_act && (c.status === 'open' || c.status === 'pending')
      ? `<form class="composer" id="composer"><label class="attach" title="Enviar arquivo (imagem, áudio, vídeo, PDF, documento)"><input type="file" id="attach-input" hidden accept="image/jpeg,image/png,image/webp,audio/*,video/mp4,video/3gpp,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"><svg viewBox="0 0 24 24"><path d="M21 11l-8.5 8.5a5 5 0 01-7-7L14 4a3.5 3.5 0 015 5l-8.5 8.5a2 2 0 01-3-3L15 7"/></svg></label><button type="button" class="attach" id="emoji-btn" title="Emojis" aria-label="Emojis" aria-expanded="false"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9.5h.01M15 9.5h.01"/></svg></button><div class="reply-wrap"><div id="emoji-pick" class="emoji-pick" role="dialog" aria-label="Emojis" hidden></div><div id="attach-chip" class="attach-chip" hidden></div><ul id="quick-pick" class="quick-pick" hidden></ul><textarea id="reply" placeholder="Escreva a resposta… (/ para mensagens rápidas, Enter envia)"></textarea></div><button class="primary">Enviar</button></form>`
      : `<div class="banner">${c.status === 'closed' ? 'Atendimento encerrado.' : c.status === 'bot' ? 'O cliente ainda está escolhendo o setor.' : 'Somente leitura.'}</div>`}`;
  const box = $('#msgs');
  box.scrollTop = box.scrollHeight;
  if (draft && $('#reply')) {
    $('#reply').value = draft.text;
    if (draft.focus) { $('#reply').focus(); $('#reply').selectionStart = $('#reply').selectionEnd = draft.pos; }
  }
  if (state.attach && $('#reply')) { const f = state.attach; pickAttach(f, draft ? !draft.focus : true); }
  renderContact(contact, c);
  state.ctx = { nome: contact.name || '', protocolo: c.helpdesk_protocol || c.protocol, setor: sectorName(c.sector_id), atendente: (state.me.name || '').split(' (')[0] };
  if (c.unread > 0 && can_act) api(`/api/conversations/${id}/read`, { method: 'POST' }).catch(() => {});
  document.querySelectorAll('#conversations li').forEach((li) => li.classList.toggle('active', li.dataset.id == id));
}

// formatação do WhatsApp (*negrito*, _itálico_, ~riscado~, ```monoespaçado```): mostra como o celular mostra
const WA_FMT = [['```', 'code'], ['*', 'b'], ['_', 'i'], ['~', 's']];
const reEsc = (t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
function waFormat(html) {
  return WA_FMT.reduce((out, [mk, tag]) => {
    const m = reEsc(mk);
    const re = new RegExp(`(^|[\\s(>"'])${m}(?=\\S)([^\\n]*?\\S)${m}(?=$|[\\s.,!?;:)<"'])`, 'g');
    return out.replace(re, `$1<${tag}>$2</${tag}>`);
  }, html);
}
const waPlain = (t) => String(t || '').replace(/(^|[\s(])(```|[*_~])(?=\S)([^\n]*?\S)\2(?=$|[\s.,!?;:)])/g, '$1$3');

function renderMsg(m) {
  const who = m.author_user_id ? `<b>${esc(state.users[m.author_user_id] || '')}</b><br>` : '';
  const ack = m.direction === 'out' ? { pending: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓ lido', failed: '⚠ falhou' }[m.ack] || '' : '';
  if (m.type === 'jack') {
    return `<div class="sys-hint" title="Os termos que levaram à sugestão ficam no registro do supervisor">${esc(m.body)} · ${hhmm(m.created_at)}</div>`;
  }
  const label = m.direction === 'internal' ? (m.type === 'transfer' ? '🔀 ' : m.type === 'status' ? '🔁 ' : m.type === 'resolution' ? '✅ ' : '📝 ') : m.type === 'rating' ? '<span class="rating-tag">⭐ Avaliação</span> ' : '';
  const media = renderMedia(m);
  const text = media && m.type === 'document' && m.direction === 'in' ? '' : waFormat(esc(m.body)); // documento recebido: o corpo é o nome
  return `<div class="msg ${m.direction}${media ? ' has-media' : ''}">${m.direction === 'internal' ? who : ''}${label}${media}${text}<time>${hhmm(m.created_at)} ${ack}</time></div>`;
}
const kb = (n) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);
function renderMedia(m) {
  if (!m.media_path) return '';
  if (m.media_path.startsWith('failed:')) return '<div class="media-off">⚠ Arquivo indisponível no WhatsApp</div>';
  if (!m.media_path.startsWith('media/')) return '<div class="media-off">⏳ Baixando arquivo…</div>';
  const url = `/media/${m.id}`;
  if (m.type === 'image') return `<a href="${url}" target="_blank" rel="noopener"><img class="media-img" src="${url}" alt="${esc(m.media_name || 'imagem')}" loading="lazy"></a>`;
  if (m.type === 'audio') return `<audio class="media-audio" controls preload="none" src="${url}"></audio>`;
  if (m.type === 'video') return `<video class="media-video" controls preload="metadata" src="${url}"></video>`;
  const ext = (m.media_name || '').split('.').pop().toUpperCase().slice(0, 4) || 'ARQ';
  return `<a class="media-doc" href="${url}" target="_blank" rel="noopener"><span>${esc(ext)}</span><b>${esc(m.media_name || 'arquivo')}</b><small>${m.media_size ? kb(m.media_size) : ''}</small></a>`;
}

// ------------------------------------------------------------ painel do contato
const tagById = (id) => (state.tags || []).find((t) => t.id == id);
const tagChip = (t, extra = '') => `<span class="chip" style="--c:${esc(t.color)}"${extra}>${esc(t.name)}</span>`;
const tagChips = (ids) => ids.map(tagById).filter(Boolean).map((t) => tagChip(t)).join('');
const fmtPhone = (p) => { const m = String(p).match(/^55(\d{2})(\d{4,5})(\d{4})$/); return m ? `(${m[1]}) ${m[2]}-${m[3]}` : p; };
const relLabel = { owner: 'Proprietário', tenant: 'Inquilino', resident: 'Morador', financial: 'Responsável financeiro' };
const convStatus = { bot: 'Triagem', pending: 'Na fila', open: 'Em atendimento', closed: 'Encerrado' };
const initials = (n) => (n || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

async function renderContact(contact, c) {
  let d = {};
  try { d = JSON.parse(contact.erp_snapshot || 'null') || {}; } catch {}
  const panel = await api(`/api/conversations/${c.id}/panel`).catch(() => null);
  if (state.current != c.id) return; // trocou de conversa enquanto carregava
  state.panel = { contact, conv: c, ...(panel || {}) };
  const info = [
    ['Telefone', fmtPhone(contact.phone)],
    ['Condomínio', d.condominio], ['Unidade', d.unidade], ['Vínculo', relLabel[d.vinculo] || d.vinculo], ['E-mail', d.email],
  ].filter(([, v]) => v);
  const tags = (panel?.tags || []).map((t) => tagChip(t)).join('');
  const notes = (panel?.notes || []).map((n) => `
    <li><p>${esc(n.body)}</p><small>${esc((n.user_name || '').split(' (')[0])} · ${hhmm(n.created_at)}
      ${n.user_id == state.me.id || canManageQuick() ? `<button type="button" class="link" data-delnote="${n.id}">apagar</button>` : ''}</small></li>`).join('');
  $('#contact-panel').innerHTML = `
    <div class="cp-head"><span class="avatar">${esc(initials(contact.name))}</span>
      <div><b>${esc(contact.name || 'Sem nome')}</b>
      ${contact.erp_person_id ? `<span class="badge-ok">${esc(relLabel[d.vinculo] || 'Cadastro no ERP')}</span>` : '<span class="badge-off">Não identificado no ERP</span>'}</div></div>
    <section><h4>Informações</h4><dl>${info.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}
      ${c.helpdesk_protocol ? `<dt>Chamado</dt><dd><b>${esc(c.helpdesk_protocol)}</b></dd>` : ''}<dt>Protocolo</dt><dd>${c.helpdesk_protocol ? esc(c.protocol) : `<b>${esc(c.protocol)}</b>`}</dd></dl>
      <div class="cp-tags"><span class="dt">Etiquetas</span><div>${tags}<button type="button" class="chip-add" id="tag-edit" title="Etiquetas do contato">+</button></div></div>
      <div id="tag-picker" class="tag-picker" hidden></div></section>
    <section><h4>Protocolos desta pessoa</h4><div id="cp-hist-box">${histHtml(panel)}</div></section>
    <section><h4>Notas do contato</h4>
      <form id="cnote-form" class="cnote-form"><textarea id="cnote" rows="2" maxlength="1000" placeholder="Ex.: prefere contato depois das 18h"></textarea><button>Salvar</button></form>
      <ul class="cp-notes">${notes}</ul></section>`;
  if (state.pickerOpen === c.id) { $('#tag-picker').hidden = false; renderTagPicker(); }
}
// protocolos da pessoa, do mais novo para o mais antigo, 5 por página (o atual aparece marcado)
function histHtml(h) {
  if (!h?.history?.length) return '<p class="meta">Primeiro atendimento desta pessoa.</p>';
  // uma linha por protocolo: número · data · situação; o assunto aparece ao passar o mouse
  const items = h.history.map((x) => `
    <li${x.current ? ' class="current" aria-current="true" title="Atendimento aberto agora"' : ` data-open="${x.id}" title="${esc(x.subject || '')}"`}>
      <b>${esc(x.protocol)}</b><time>${hhmm(x.created_at).split(',')[0]}</time><small class="st st-${x.status}">${convStatus[x.status]}</small></li>`).join('');
  const pager = h.history_pages > 1 ? `<nav class="cp-pager" aria-label="Páginas de protocolos">
      <button type="button" data-hpage="${h.history_page - 1}" ${h.history_page <= 1 ? 'disabled' : ''} aria-label="Mais novos">‹</button>
      <span>${h.history_page} de ${h.history_pages} · ${h.history_total} protocolos</span>
      <button type="button" data-hpage="${h.history_page + 1}" ${h.history_page >= h.history_pages ? 'disabled' : ''} aria-label="Mais antigos">›</button></nav>` : '';
  return `<ul class="cp-hist cp-proto">${items}</ul>${pager}${h.history_hidden > 0 ? `<p class="meta">+${h.history_hidden} em outros setores</p>` : ''}`;
}
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-hpage]');
  if (!b || b.disabled || !state.current) return;
  try {
    const r = await api(`/api/conversations/${state.current}/history?pagina=${b.dataset.hpage}`);
    Object.assign(state.panel, { history: r.items, history_page: r.page, history_pages: r.pages, history_total: r.total, history_hidden: r.hidden });
    $('#cp-hist-box').innerHTML = histHtml(state.panel);
  } catch (err) { toast(err.message); }
});
function renderTagPicker() {
  const mine = (state.panel.tags || []).map((t) => t.id);
  const all = state.tags || [];
  $('#tag-picker').innerHTML = (all.length ? all.map((t) => `<label><input type="checkbox" value="${t.id}" ${mine.includes(t.id) ? 'checked' : ''}> ${tagChip(t)}</label>`).join('')
    : '<p class="meta">Nenhuma etiqueta cadastrada.</p>')
    + (canManageQuick() ? '<button type="button" class="link" id="tags-manage">Gerenciar etiquetas</button>' : '');
}
document.addEventListener('click', async (e) => {
  if (e.target.id === 'tag-edit') {
    const p = $('#tag-picker'); p.hidden = !p.hidden;
    state.pickerOpen = p.hidden ? null : state.panel.conv.id;
    if (!p.hidden) renderTagPicker();
    return;
  }
  if (e.target.id === 'tags-manage') { openTagsDialog(); return; }
  const open = e.target.closest('[data-open]');
  if (open) { openConversation(open.dataset.open).catch((err) => toast(err.message)); return; }
  const del = e.target.dataset.delnote;
  if (del && confirm('Apagar esta nota?')) {
    try { await api(`/api/conversations/${state.current}/contact-notes/${del}/delete`, { method: 'POST' }); renderContact(state.panel.contact, state.panel.conv); } catch (err) { toast(err.message); }
  }
});
document.addEventListener('change', async (e) => {
  if (!e.target.closest('#tag-picker')) return;
  const ids = [...document.querySelectorAll('#tag-picker input:checked')].map((i) => Number(i.value));
  try {
    state.panel.tags = await api(`/api/conversations/${state.current}/tags`, { method: 'POST', body: { tag_ids: ids } });
    const { contact, conv } = state.panel; await renderContact(contact, conv); loadList();
  } catch (err) { toast(err.message); e.target.checked = !e.target.checked; }
});
document.addEventListener('submit', async (e) => {
  if (e.target.id !== 'cnote-form') return;
  e.preventDefault();
  const body = $('#cnote').value.trim(); if (!body) return;
  try { await api(`/api/conversations/${state.current}/contactnotes`, { method: 'POST', body: { body } }); renderContact(state.panel.contact, state.panel.conv); }
  catch (err) { toast(err.message); }
});

// cadastro de etiquetas (supervisor e gerentes)
async function loadTags() {
  state.tags = (await api('/api/tags')).map((t) => ({ ...t, id: Number(t.id) }));
  const sel = $('#tag-filter');
  if (sel) sel.innerHTML = '<option value="">Todas as etiquetas</option>' + state.tags.map((t) => `<option value="${t.id}" ${state.tagFilter == t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
}
const TAG_COLORS = ['#12804A', '#C9A227', '#2F6FDE', '#C2410C', '#B42318', '#7A3EC8', '#0E7490', '#5F6B66'];
function renderTagsDialog() {
  $('#tags-list').innerHTML = (state.tags || []).map((t) => `<li>${tagChip(t)} <small>${t.contacts} contato(s)</small>
    <span><button type="button" data-tedit="${t.id}">Editar</button><button type="button" data-tdel="${t.id}">Excluir</button></span></li>`).join('')
    || '<li class="empty">Nenhuma etiqueta ainda.</li>';
}
async function openTagsDialog() { await loadTags(); renderTagsDialog(); tagForm(null); $('#tags-dialog').showModal(); }
function tagForm(t) {
  $('#tag-id').value = t?.id || ''; $('#tag-name').value = t?.name || '';
  const color = (t?.color || TAG_COLORS[0]).toUpperCase();
  const custom = !TAG_COLORS.includes(color);
  $('#tag-colors').innerHTML = TAG_COLORS.map((c) => `<label style="--c:${c}" title="${c}"><input type="radio" name="tag-color" value="${c}" ${c === color ? 'checked' : ''}><span></span></label>`).join('')
    + `<label class="custom${custom ? ' on' : ''}" style="--c:${custom ? color : 'transparent'}" title="Escolher outra cor"><input type="color" id="tag-custom" value="${custom ? color.toLowerCase() : '#12804a'}" aria-label="Escolher outra cor"><i></i></label>`
    + `<input class="tag-hex" id="tag-hex" value="${color}" maxlength="7" aria-label="Código da cor" title="Código da cor (ex.: #12804A)">`;
  tagPreview();
  $('#tag-save').textContent = t ? 'Salvar' : 'Criar etiqueta';
}
// cor da etiqueta: as sugeridas, o seletor livre ou o código (#RRGGBB)
function tagColor() {
  const custom = $('#tag-colors .custom');
  return custom.classList.contains('on') ? $('#tag-custom').value.toUpperCase() : document.querySelector('input[name=tag-color]:checked')?.value;
}
function setCustomColor(hex) {
  document.querySelectorAll('input[name=tag-color]').forEach((r) => { r.checked = r.value === hex; });
  const preset = TAG_COLORS.includes(hex);
  const custom = $('#tag-colors .custom');
  custom.classList.toggle('on', !preset);
  custom.style.setProperty('--c', preset ? 'transparent' : hex);
  if (!preset) $('#tag-custom').value = hex.toLowerCase();
  tagPreview();
}
function tagPreview() {
  const c = tagColor() || TAG_COLORS[0];
  if (document.activeElement !== $('#tag-hex')) $('#tag-hex').value = c;
  let p = $('#tag-preview');
  if (!p) { $('#tag-colors').insertAdjacentHTML('afterend', '<div class="tag-preview" id="tag-preview"></div>'); p = $('#tag-preview'); }
  p.innerHTML = `Prévia: ${tagChip({ name: $('#tag-name').value.trim() || 'Etiqueta', color: c })}`;
}
$('#tag-colors').addEventListener('input', (e) => {
  if (e.target.id === 'tag-custom') setCustomColor(e.target.value.toUpperCase());
  if (e.target.id === 'tag-hex') { const v = e.target.value.trim().toUpperCase(); if (/^#[0-9A-F]{6}$/.test(v)) setCustomColor(v); }
});
$('#tag-colors').addEventListener('change', (e) => { if (e.target.name === 'tag-color') setCustomColor(e.target.value); });
$('#tag-name').addEventListener('input', tagPreview);
$('#nav-tags')?.addEventListener('click', (e) => { e.preventDefault(); openTagsDialog(); });
$('#tags-close').addEventListener('click', () => $('#tags-dialog').close());
$('#tags-dialog').addEventListener('close', () => { if (state.panel) renderContact(state.panel.contact, state.panel.conv); loadList(); });
$('#tags-list').addEventListener('click', async (e) => {
  const ed = e.target.dataset.tedit; const del = e.target.dataset.tdel;
  if (ed) tagForm(state.tags.find((t) => t.id == ed));
  if (del && confirm('Excluir esta etiqueta? Ela sai de todos os contatos.')) {
    try { await api(`/api/tags/${del}/delete`, { method: 'POST' }); await loadTags(); renderTagsDialog(); } catch (err) { toast(err.message); }
  }
});
$('#tag-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = $('#tag-id').value;
  try {
    await api(id ? `/api/tags/${id}` : '/api/tags', { method: 'POST', body: { name: $('#tag-name').value, color: tagColor() } });
    await loadTags(); renderTagsDialog(); tagForm(null);
  } catch (err) { toast(err.message); }
});
$('#tag-filter')?.addEventListener('change', (e) => { state.tagFilter = e.target.value; loadList(); });

// ------------------------------------------------------------ ações
document.addEventListener('click', async (e) => {
  const li = e.target.closest('#conversations li[data-id]');
  if (li) return openConversation(li.dataset.id);
  const tab = e.target.closest('#tabs button');
  if (tab) {
    document.querySelectorAll('#tabs button').forEach((b) => b.classList.toggle('active', b === tab));
    state.tab = tab.dataset.tab; state.search = ''; $('#search').value = '';
    return loadList();
  }
  const act = e.target.closest('[data-act]')?.dataset.act;
  if (act === 'schedule') { location.href = `/agendamentos?c=${state.current}&voltar=1`; return; }
  if (!act || !state.current) return;
  try {
    if (act === 'accept') await api(`/api/conversations/${state.current}/accept`, { method: 'POST' });
    if (act === 'note') {
      const note = prompt('Nota interna (o cliente não vê):');
      if (note) await api(`/api/conversations/${state.current}/notes`, { method: 'POST', body: { note } });
    }
    if (act === 'transfer') {
      $('#transfer-sector').innerHTML = state.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
      $('#transfer-note').value = ''; $('#transfer-count').textContent = '0/500';
      $('#transfer-history').checked = true;
      document.querySelector('input[name=xfer-mode][value=sector]').checked = true;
      fillTransferUsers(); toggleTransferMode();
      $('#transfer-dialog').showModal();
      return;
    }
    if (act === 'close') { $('#close-resolution').value = ''; $('#close-dialog').showModal(); return; }
    if (act === 'ticket') {
      // começa com o que o cliente escreveu: a primeira linha vira o assunto, as últimas mensagens a descrição
      const said = [...document.querySelectorAll('#msgs .msg.in')].map((m) => m.childNodes[0]?.textContent?.trim() || '').filter(Boolean);
      $('#ticket-subject').value = (said[0] || '').split('\n')[0].slice(0, 120);
      $('#ticket-desc').value = said.slice(-5).join('\n');
      $('#ticket-dialog').showModal(); $('#ticket-subject').focus();
      return;
    }
    await openConversation(state.current); loadList();
  } catch (err) { toast(err.message); }
});

// mudança de status pelo seletor do topo da conversa
$('#chat').addEventListener('change', async (e) => {
  const sel = e.target.closest('#status-select');
  if (!sel || !state.current) return;
  const to = sel.value;
  if (to === 'closed') { sel.value = sel.dataset.cur; $('#close-resolution').value = ''; $('#close-dialog').showModal(); return; }
  try {
    await api(`/api/conversations/${state.current}/status`, { method: 'POST', body: { status: to } });
  } catch (err) { toast(err.message); }
  await openConversation(state.current); loadList();
});
$('#ticket-dialog').addEventListener('close', async () => {
  if ($('#ticket-dialog').returnValue !== 'ok') return;
  try {
    const c = await api(`/api/conversations/${state.current}/ticket`, { method: 'POST', body: { subject: $('#ticket-subject').value, description: $('#ticket-desc').value } });
    toast(`Chamado ${c.helpdesk_protocol || c.protocol} aberto no Help Desk.`);
    await openConversation(state.current); loadList();
  } catch (err) { toast(err.message); }
});
$('#transfer-dialog').addEventListener('close', async () => {
  if ($('#transfer-dialog').returnValue !== 'ok') return;
  try {
    const toUser = document.querySelector('input[name=xfer-mode]:checked').value === 'user' ? +$('#transfer-user').value || null : null;
    const opt = $('#transfer-user').selectedOptions[0];
    const sectorId = toUser ? +opt.dataset.sector : +$('#transfer-sector').value;
    await api(`/api/conversations/${state.current}/transfer`, { method: 'POST', body: {
      sector_id: sectorId, user_id: toUser, note: $('#transfer-note').value, keep_history: $('#transfer-history').checked } });
    loadList();
    // quem transferiu pode ter perdido o acesso (outro setor): nesse caso só fecha a conversa
    await openConversation(state.current).catch((err) => {
      if (err.status !== 403) throw err;
      state.current = null; $('#chat').innerHTML = '<div class="empty">Conversa transferida para outro setor.</div>'; $('#contact-panel').innerHTML = '';
    });
  } catch (err) { toast(err.message); }
});
// Trocar atendente: atendentes de todos os setores (menos eu), agrupados por setor
function fillTransferUsers() {
  const groups = state.sectors.map((s) => {
    const people = state.staff.filter((p) => p.sector_id == s.id && p.user_id != state.me.id);
    return people.length ? `<optgroup label="${esc(s.name)}">${people.map((p) => `<option value="${p.user_id}" data-sector="${s.id}">${esc(p.name)}</option>`).join('')}</optgroup>` : '';
  }).join('');
  $('#transfer-user').innerHTML = '<option value="">Selecione um atendente</option>' + groups;
}
function toggleTransferMode() {
  const user = document.querySelector('input[name=xfer-mode]:checked').value === 'user';
  $('#transfer-user').disabled = !user; $('#transfer-sector').disabled = user;
  $('#transfer-user').required = user;
}
document.querySelectorAll('input[name=xfer-mode]').forEach((r) => r.addEventListener('change', toggleTransferMode));
$('#transfer-note').addEventListener('input', (e) => { $('#transfer-count').textContent = `${e.target.value.length}/500`; });

$('#close-dialog').addEventListener('close', async () => {
  if ($('#close-dialog').returnValue !== 'ok') return;
  try {
    await api(`/api/conversations/${state.current}/close`, { method: 'POST', body: { resolution: $('#close-resolution').value } });
    await openConversation(state.current); loadList();
  } catch (err) { toast(err.message); }
});

document.addEventListener('submit', async (e) => {
  if (e.target.id === 'composer') {
    e.preventDefault();
    const text = $('#reply').value.trim();
    if (state.attach) return sendAttach(text);
    if (!text) return;
    $('#reply').value = '';
    try { await api(`/api/conversations/${state.current}/reply`, { method: 'POST', body: { text } }); await openConversation(state.current); }
    catch (err) { toast(err.message); $('#reply').value = text; }
  }
  if (e.target.id === 'search-form') { e.preventDefault(); state.search = $('#search').value.trim(); loadList(); }
});
document.addEventListener('keydown', (e) => {
  if (e.target.id === 'reply' && quickPickKey(e)) return;
  if (e.target.id === 'reply' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.form.requestSubmit(); }
});

// ------------------------------------------------------------ arquivos
const MAX_FILE = 16 * 1024 * 1024;
function pickAttach(file, noFocus = false) {
  if (!file) return;
  if (file.size > MAX_FILE) { toast('Arquivo maior que 16 MB.'); return; }
  state.attach = file;
  const chip = $('#attach-chip');
  chip.innerHTML = `📎 <b>${esc(file.name)}</b> <small>${kb(file.size)}</small> <button type="button" id="attach-clear" title="Remover">✕</button>`;
  chip.hidden = false;
  $('#reply').placeholder = file.type.startsWith('audio/') ? 'Áudio não leva legenda. Enter envia.' : 'Legenda (opcional). Enter envia o arquivo.';
  if (!noFocus) $('#reply').focus();
}
function clearAttach() {
  state.attach = null;
  if ($('#attach-chip')) { $('#attach-chip').hidden = true; $('#attach-chip').innerHTML = ''; }
  if ($('#attach-input')) $('#attach-input').value = '';
  if ($('#reply')) $('#reply').placeholder = 'Escreva a resposta… (/ para mensagens rápidas, Enter envia)';
}
async function sendAttach(caption) {
  const fd = new FormData();
  fd.append('file', state.attach);
  if (caption) fd.append('caption', caption);
  const btn = $('#composer button.primary'); btn.disabled = true; btn.textContent = 'Enviando…';
  try {
    const res = await fetch(`/api/conversations/${state.current}/attach`, { method: 'POST', headers: { 'X-CSRF': csrf }, body: fd });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || (res.status === 413 ? 'Arquivo grande demais.' : 'Erro ao enviar o arquivo.'));
    clearAttach(); $('#reply').value = '';
    await openConversation(state.current);
  } catch (err) { toast(err.message); btn.disabled = false; btn.textContent = 'Enviar'; }
}
document.addEventListener('change', (e) => {
  if (e.target.id === 'attach-input') pickAttach(e.target.files[0]);
});
document.addEventListener('click', (e) => {
  if (e.target.id === 'attach-clear') clearAttach();
  if (e.target.id === 'search-clear') { state.search = ''; $('#search').value = ''; loadList(); }
});
// a busca do topo: apagar o texto (ou o "x" do campo) volta para a lista
$('#search').addEventListener('search', () => { if (!$('#search').value.trim() && state.search) { state.search = ''; loadList(); } });
// colar uma imagem (print) direto no campo de resposta
document.addEventListener('paste', (e) => {
  if (e.target.id !== 'reply') return;
  const f = [...(e.clipboardData?.files || [])][0];
  if (f) { e.preventDefault(); pickAttach(f); }
});
// arrastar um arquivo para a conversa
document.addEventListener('dragover', (e) => { if (e.target.closest?.('#chat') && $('#composer')) e.preventDefault(); });
document.addEventListener('drop', (e) => {
  if (!e.target.closest?.('#chat') || !$('#composer')) return;
  e.preventDefault(); pickAttach(e.dataTransfer.files[0]);
});

// ------------------------------------------------------------ mensagens rápidas
async function loadQuick() { state.quick = await api('/api/quick-messages'); return state.quick; }
const fillQuick = (body) => {
  const x = state.ctx || {};
  const first = (x.nome || '').trim().split(' ')[0] || '';
  return body.replace(/\{(primeiro_nome|nome|protocolo|setor|atendente)\}/g, (_, k) => (k === 'primeiro_nome' ? first : x[k] || ''))
    .replace(/ +([,!.?])/g, '$1').replace(/  +/g, ' ');
};
// "/termo" no começo do campo ou depois de um espaço/linha
const quickQuery = (ta) => { const m = ta.value.slice(0, ta.selectionStart).match(/(^|\s)\/([\w-]*)$/); return m ? m[2].toLowerCase() : null; };
function renderQuickPick() {
  const ta = $('#reply'); const pick = $('#quick-pick');
  if (!ta || !pick) return;
  const q = quickQuery(ta);
  // atalho que começa com o termo primeiro, depois atalho que contém, depois o texto
  const rank = (m) => (m.shortcut.startsWith(q) ? 0 : m.shortcut.includes(q) ? 1 : m.body.toLowerCase().includes(q) ? 2 : 9);
  const items = q === null ? [] : (state.quick || []).filter((m) => rank(m) < 9).sort((a, b) => rank(a) - rank(b)).slice(0, 8);
  pick.hidden = items.length === 0;
  pick.innerHTML = items.map((m, i) => `<li data-id="${m.id}" class="${i === 0 ? 'sel' : ''}"><b>/${esc(m.shortcut)}</b><span>${esc(fillQuick(m.body))}</span></li>`).join('');
}
function useQuick(id) {
  const m = (state.quick || []).find((x) => x.id == id); const ta = $('#reply');
  if (!m || !ta) return;
  const before = ta.value.slice(0, ta.selectionStart).replace(/\/[\w-]*$/, '');
  const text = fillQuick(m.body);
  ta.value = before + text + ta.value.slice(ta.selectionStart);
  ta.selectionStart = ta.selectionEnd = before.length + text.length;
  $('#quick-pick').hidden = true; ta.focus();
}
function quickPickKey(e) {
  const pick = $('#quick-pick');
  if (!pick || pick.hidden) return false;
  const li = [...pick.children]; let i = li.findIndex((x) => x.classList.contains('sel'));
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault(); i = (i + (e.key === 'ArrowDown' ? 1 : li.length - 1)) % li.length;
    li.forEach((x, j) => x.classList.toggle('sel', j === i)); li[i].scrollIntoView({ block: 'nearest' }); return true;
  }
  if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); useQuick(li[Math.max(0, i)].dataset.id); return true; }
  if (e.key === 'Escape') { e.preventDefault(); pick.hidden = true; return true; }
  return false;
}
document.addEventListener('input', (e) => { if (e.target.id === 'reply') renderQuickPick(); });
document.addEventListener('mousedown', (e) => { const li = e.target.closest('#quick-pick li'); if (li) { e.preventDefault(); useQuick(li.dataset.id); } });

// cadastro (supervisor: gerais e de todos os setores; gerente: do seu setor)
const canManageQuick = () => state.me.supervisor || (state.me.manager_sectors || []).length > 0;
function renderQuickList() {
  $('#quick-list').innerHTML = (state.quick || []).map((m) => `
    <li><div><b>/${esc(m.shortcut)}</b> <small>${esc(m.sector_name || 'Todos os setores')}</small><p>${esc(m.body)}</p></div>
    ${m.can_edit ? `<span><button type="button" data-qedit="${m.id}">Editar</button><button type="button" data-qdel="${m.id}">Excluir</button></span>` : ''}</li>`).join('')
    || '<li class="empty">Nenhuma mensagem rápida ainda.</li>';
  $('#quick-new').hidden = !canManageQuick();
}
function quickEdit(m) {
  const opts = [];
  if (state.me.supervisor) opts.push('<option value="">Todos os setores</option>');
  state.sectors.filter((s) => state.me.supervisor || (state.me.manager_sectors || []).includes(Number(s.id)))
    .forEach((s) => opts.push(`<option value="${s.id}">${esc(s.name)}</option>`));
  $('#quick-sector').innerHTML = opts.join('');
  $('#quick-id').value = m?.id || ''; $('#quick-shortcut').value = m?.shortcut || ''; $('#quick-body').value = m?.body || '';
  $('#quick-sector').value = m?.sector_id ?? (state.me.supervisor ? '' : $('#quick-sector').value);
  $('#quick-form').hidden = false; $('#quick-actions').hidden = true; $('#quick-shortcut').focus();
}
const quickFormClose = () => { $('#quick-form').hidden = true; $('#quick-actions').hidden = false; };
$('#nav-quick')?.addEventListener('click', async (e) => {
  e.preventDefault(); await loadQuick(); renderQuickList(); quickFormClose(); $('#quick-dialog').showModal();
});
$('#quick-new').addEventListener('click', () => quickEdit(null));
$('#quick-cancel').addEventListener('click', quickFormClose);
$('#quick-close').addEventListener('click', () => $('#quick-dialog').close());
$('#quick-list').addEventListener('click', async (e) => {
  const ed = e.target.dataset.qedit; const del = e.target.dataset.qdel;
  if (ed) quickEdit(state.quick.find((m) => m.id == ed));
  if (del && confirm('Excluir esta mensagem rápida?')) {
    try { await api(`/api/quick-messages/${del}/delete`, { method: 'POST' }); await loadQuick(); renderQuickList(); } catch (err) { toast(err.message); }
  }
});
$('#quick-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = $('#quick-id').value;
  try {
    await api(id ? `/api/quick-messages/${id}` : '/api/quick-messages', { method: 'POST',
      body: { shortcut: $('#quick-shortcut').value, body: $('#quick-body').value, sector_id: $('#quick-sector').value } });
    await loadQuick(); renderQuickList(); quickFormClose();
  } catch (err) { toast(err.message); }
});
$('#sector-filter').addEventListener('change', (e) => { state.sector = e.target.value; loadList(); });

// ------------------------------------------------------------ tempo real (SSE)
let refreshTimer;
function scheduleRefresh(convId) {
  clearTimeout(refreshTimer);
  refreshTimer = setTimeout(() => {
    loadList();
    if (state.current && (convId == null || convId == state.current)) {
      openConversation(state.current).catch((err) => {
        // perdeu o acesso (ex.: transferida para outro setor): fecha a conversa sem alarde
        if (err.status === 403) { state.current = null; $('#chat').innerHTML = '<div class="empty">Conversa transferida para outro setor.</div>'; $('#contact-panel').innerHTML = ''; }
      });
    }
  }, 150);
}
function connect() {
  const es = new EventSource('/events');
  es.onopen = () => { $('#live').textContent = '● Online'; $('#live').classList.add('on'); };
  es.onerror = () => { $('#live').dataset.sse = '1'; $('#live').textContent = '● reconectando'; $('#live').classList.remove('on'); };
  ['conversation.created', 'conversation.updated', 'conversation.transferred', 'message.created', 'message.ack', 'message.media'].forEach((t) =>
    es.addEventListener(t, (ev) => {
      const d = JSON.parse(ev.data);
      if (t === 'message.created' && d.direction === 'in') {
        window.talkAlert?.();
        if (document.hidden) document.title = '(nova) Connect Talk';
      }
      if (t === 'conversation.created') window.talkAlert?.();
      scheduleRefresh(d.conversation_id);
    }));
}
document.addEventListener('visibilitychange', () => { if (!document.hidden) document.title = 'Connect Talk'; });

// ------------------------------------------------------------ início
(async function init() {
  const me = await api('/api/me');
  Object.assign(state, { me: me.user, sectors: me.sectors, users: me.users, staff: me.staff || [] });
  $('#sector-filter').innerHTML += me.sectors.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  await loadTags().catch(() => {});
  if (!(me.user.supervisor || (me.user.manager_sectors || []).length)) $('#nav-tags')?.remove();
  // links vindos de fora: /?c=ID abre a conversa (Fila, chamado do ERP); /?abrir=... abre um diálogo;
  // /?q=... vem da busca do topo em outra tela; /?aba=mine vem das notificações
  const qs = new URLSearchParams(location.search);
  if (['pending', 'mine', 'open', 'closed'].includes(qs.get('aba'))) {
    state.tab = qs.get('aba');
    document.querySelectorAll('#tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === state.tab));
  }
  if (qs.get('q')) { state.search = qs.get('q').trim(); $('#search').value = state.search; }
  await loadList();
  loadQuick().catch(() => {});
  if (qs.get('c')) openConversation(Number(qs.get('c'))).catch((err) => toast(err.message));
  const abrir = qs.get('abrir');
  if (abrir === 'mensagens') $('#nav-quick')?.click();
  if (abrir === 'etiquetas') $('#nav-tags')?.click();
  if (abrir === 'busca') $('#search').focus();
  if (qs.toString()) history.replaceState(null, '', '/');
  connect();
})();


// ------------------------------------------------------------ emojis no campo de resposta
const EMOJIS = {
  'Mais usados': '😊 🙂 😉 😄 😁 😅 😂 🥰 😍 🙏 👍 👏 🙌 💪 👋 🤝 ✅ ✔️ ❤️ 💚 💙 ⭐ 🎉 ✨',
  'Rostos': '😀 😃 😆 🤗 🤩 😎 🤔 🫡 😌 😇 🥲 😢 😭 😔 😕 🙁 😮 😯 😬 😴 🤒 🤧 😷 🙃',
  'Gestos': '👌 ✌️ 🤞 👊 ✋ 🖐️ 👆 👇 👉 👈 🫶 🙋 🤷 🙆 🙅 💁',
  'Condomínio': '🏢 🏠 🔑 🚪 🛗 🚗 🅿️ 📦 📬 🔧 🔨 🧰 🪛 💡 🔌 💧 🚿 🚰 🔥 🧯 🧹 🗑️ 🌳 🐶',
  'Atendimento': '📞 📱 💬 📧 📄 📋 📎 🧾 💰 💳 🏦 📅 ⏰ ⏳ 📍 ⚠️ ❗ ❓ 🚫 ⛔ 🆗 🆕 ☑️ ➡️',
};
function emojiPick(open) {
  const pick = $('#emoji-pick'); const btn = $('#emoji-btn');
  if (!pick) return;
  if (open && !pick.innerHTML) {
    pick.innerHTML = Object.entries(EMOJIS).map(([k, v]) => `<h5>${esc(k)}</h5><div>${v.split(' ').map((e) => `<button type="button" data-emoji="${e}" title="${e}">${e}</button>`).join('')}</div>`).join('');
  }
  pick.hidden = !open; btn.setAttribute('aria-expanded', String(open));
  if (open) $('#quick-pick').hidden = true;
}
function insertAtCursor(ta, text) {
  const a = ta.selectionStart ?? ta.value.length, b = ta.selectionEnd ?? a;
  ta.value = ta.value.slice(0, a) + text + ta.value.slice(b);
  ta.selectionStart = ta.selectionEnd = a + text.length;
  ta.dispatchEvent(new Event('input', { bubbles: true }));
}
document.addEventListener('mousedown', (e) => {
  const em = e.target.closest('#emoji-pick [data-emoji]');
  if (em) { e.preventDefault(); insertAtCursor($('#reply'), em.dataset.emoji); $('#reply').focus(); return; } // continua aberto para escolher mais de um
  if (e.target.closest('#emoji-btn')) { e.preventDefault(); emojiPick($('#emoji-pick').hidden); $('#reply').focus(); return; }
  if ($('#emoji-pick') && !$('#emoji-pick').hidden && !e.target.closest('#emoji-pick')) emojiPick(false);
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('#emoji-pick') && !$('#emoji-pick').hidden) emojiPick(false); });
