/*
 * Connect — modal do sistema no lugar de alert, confirm e prompt do navegador (ADR 0320, segue a 0316 do ERP).
 * Sem dependências: Studio, Help Desk, site e app web do morador. O ERP tem a mesma modal no próprio erp.js.
 * A modal diz o que está acontecendo, o que vai acontecer e se dá para desfazer.
 *   ConnectDialog.confirm({ title, message, undo: true|false, undoText, confirmLabel, cancelLabel, tone }) → Promise<boolean>
 *   ConnectDialog.alert({ title, message }) → Promise
 *   ConnectDialog.prompt({ title, message, label, value, multiline, type, confirmLabel }) → Promise<string|null>
 *   ConnectDialog.copy(rótulo, valor) → texto selecionado para copiar quando a área de transferência falha
 * Declarativo: <form data-cn-confirm="Pergunta? Explicação."> ou <button type="submit" data-cn-confirm="...">,
 * com data-confirm-undo="sim|nao", data-confirm-undo-text, data-confirm-action e data-confirm-tone.
 * Os avisos do canto (connect-notice.js) ficam acima da modal.
 */
(function () {
  'use strict';
  if (window.ConnectDialog) return;

  var CSS = '' +
    '.cn-dialog{--cn-tone:#07884c;--cn-soft:#eaf7ef;width:min(480px,calc(100vw - 32px));max-height:calc(100vh - 48px);padding:0;border:0;border-radius:15px;background:#fff;color:#121a31;box-shadow:0 30px 80px rgba(4,30,22,.32);font-family:inherit;text-align:left}' +
    '.cn-dialog::backdrop{background:rgba(4,30,22,.45);backdrop-filter:blur(2px)}' +
    '.cn-dialog[open]{animation:cn-dialog-in .22s cubic-bezier(.2,.7,.2,1)}' +
    '.cn-dialog--danger{--cn-tone:#d92d36;--cn-soft:#fdecee}' +
    '.cn-dialog--warning{--cn-tone:#e0a019;--cn-soft:#fff8e8}' +
    '.cn-dialog--info{--cn-tone:#18587d;--cn-soft:#eaf3fc}' +
    '.cn-dialog__head{display:flex;align-items:center;gap:14px;padding:22px 22px 6px}' +
    '.cn-dialog__head h2{margin:0;font-size:17px;font-weight:700;line-height:1.35;color:#121a31}' +
    '.cn-dialog__icon{flex:0 0 42px;display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:var(--cn-soft);color:var(--cn-tone)}' +
    '.cn-dialog__body{display:grid;gap:10px;padding:8px 22px 20px 78px;color:#65746d;font-size:14px;line-height:1.5}' +
    '.cn-dialog__body p{margin:0}' +
    '.cn-dialog__field{display:grid;gap:6px;color:#121a31;font-size:13px;font-weight:600}' +
    '.cn-dialog__field input,.cn-dialog__field textarea{width:100%;box-sizing:border-box;min-height:40px;padding:9px 12px;border:1px solid #c9d8d0;border-radius:8px;background:#fff;color:#121a31;font:inherit;font-weight:400}' +
    '.cn-dialog__field textarea{min-height:220px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}' +
    '.cn-dialog__field input:focus,.cn-dialog__field textarea:focus{outline:2px solid #087d49;outline-offset:1px}' +
    '.cn-dialog__undo{display:flex;align-items:flex-start;gap:10px;margin-top:4px;padding:10px 12px;border-radius:11px;font-size:13.5px}' +
    '.cn-dialog__undo svg{flex:0 0 18px;margin-top:1px}' +
    '.cn-dialog__undo--yes{background:#e3f4ec;color:#0b5a40}' +
    '.cn-dialog__undo--no{background:#fdecee;color:#8a1c13}' +
    '.cn-dialog__foot{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #dfe8e3;background:#f6faf8}' +
    '.cn-dialog__btn{min-height:40px;padding:0 18px;border:1px solid #c9d8d0;border-radius:8px;background:#fff;color:#121a31;font:inherit;font-size:14px;font-weight:600;cursor:pointer}' +
    '.cn-dialog__btn:hover{background:#f6faf8}' +
    '.cn-dialog__btn:focus-visible{outline:3px solid rgba(7,136,76,.35);outline-offset:2px}' +
    '.cn-dialog__btn--go{border-color:var(--cn-tone);background:var(--cn-tone);color:#fff}' +
    '.cn-dialog--warning .cn-dialog__btn--go{border-color:#9a6400;background:#9a6400}' +
    '.cn-dialog__btn--go:hover{background:var(--cn-tone);filter:brightness(.92)}' +
    '@keyframes cn-dialog-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}' +
    '@media (max-width:600px){.cn-dialog__body{padding-left:22px}.cn-dialog__foot>.cn-dialog__btn{flex:1}}' +
    '@media (prefers-reduced-motion:reduce){.cn-dialog[open]{animation:none}}';

  var ICONS = {
    alert: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M12 7.5v6" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/><circle cx="12" cy="17.4" r="1.6" fill="currentColor"/></svg>',
    info: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="6.8" r="1.6" fill="currentColor"/><path d="M12 10.8v7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>',
    yes: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M9 14 4 9l5-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    no: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>'
  };
  var seq = 0;

  function style() {
    if (document.getElementById('cn-dialog-style')) return;
    var tag = document.createElement('style');
    tag.id = 'cn-dialog-style';
    tag.textContent = CSS;
    document.head.appendChild(tag);
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  // Avisos do canto por cima da modal: a região deles é reaberta como popover depois da modal.
  function raiseNotices() {
    if (window.ConnectNotice && typeof window.ConnectNotice.raise === 'function') window.ConnectNotice.raise();
  }

  function open(options) {
    var o = options || {};
    style();
    var tone = ['danger', 'warning', 'info', 'primary'].indexOf(o.tone) >= 0 ? o.tone : 'primary';
    var id = 'cn-dialog-' + (++seq);
    var dialog = el('dialog', 'cn-dialog cn-dialog--' + tone);
    dialog.setAttribute('aria-labelledby', id + '-title');
    dialog.setAttribute('aria-describedby', id + '-text');
    var head = el('div', 'cn-dialog__head');
    var badge = el('span', 'cn-dialog__icon');
    badge.innerHTML = tone === 'danger' || tone === 'warning' ? ICONS.alert : ICONS.info;
    var title = el('h2', null, o.title || 'Confirme');
    title.id = id + '-title';
    head.appendChild(badge);
    head.appendChild(title);
    var body = el('div', 'cn-dialog__body');
    body.id = id + '-text';
    String(o.message || '').split(/\n+/).forEach(function (line) {
      if (line.trim()) body.appendChild(el('p', null, line.trim()));
    });
    var input = null;
    if (o.field) {
      var label = el('label', 'cn-dialog__field');
      label.appendChild(el('span', null, o.field.label || ''));
      input = o.field.multiline ? el('textarea') : el('input');
      if (!o.field.multiline) input.type = o.field.type || 'text';
      if (o.field.readOnly) input.readOnly = true;
      if (o.field.min != null) input.min = o.field.min;
      if (o.field.max != null) input.max = o.field.max;
      if (o.field.placeholder) input.placeholder = o.field.placeholder;
      input.value = o.field.value == null ? '' : String(o.field.value);
      label.appendChild(input);
      body.appendChild(label);
    }
    if (o.undo === true || o.undo === false) {
      var undo = el('div', 'cn-dialog__undo cn-dialog__undo--' + (o.undo ? 'yes' : 'no'));
      undo.innerHTML = o.undo ? ICONS.yes : ICONS.no;
      var undoText = el('span');
      undoText.appendChild(el('strong', null, o.undo ? 'Dá para desfazer.' : 'Não dá para desfazer.'));
      if (o.undoText) undoText.appendChild(document.createTextNode(' ' + o.undoText));
      undo.appendChild(undoText);
      body.appendChild(undo);
    }
    var foot = el('div', 'cn-dialog__foot');
    var cancel = null;
    if (o.cancelLabel !== false) {
      cancel = el('button', 'cn-dialog__btn', o.cancelLabel || 'Voltar');
      cancel.type = 'button';
      foot.appendChild(cancel);
    }
    var ok = el('button', 'cn-dialog__btn cn-dialog__btn--go', o.confirmLabel || 'Confirmar');
    ok.type = 'button';
    foot.appendChild(ok);
    dialog.appendChild(head);
    dialog.appendChild(body);
    dialog.appendChild(foot);
    document.body.appendChild(dialog);

    var back = document.activeElement;
    return new Promise(function (resolve) {
      var answer = false;
      var finish = function (value) { answer = value; if (dialog.open) dialog.close(); else dialog.dispatchEvent(new Event('close')); };
      ok.addEventListener('click', function () { finish(true); });
      if (cancel) cancel.addEventListener('click', function () { finish(false); });
      dialog.addEventListener('click', function (event) { if (event.target === dialog && cancel) finish(false); });
      if (input && !o.field.multiline) input.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); finish(true); } });
      dialog.addEventListener('close', function () {
        if (dialog.parentNode) dialog.parentNode.removeChild(dialog);
        if (back && typeof back.focus === 'function' && document.contains(back)) back.focus();
        resolve(input ? (answer ? input.value : null) : answer);
      });
      if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
      raiseNotices();
      // Ação que não dá para desfazer começa com o foco em Voltar: um Enter distraído não confirma.
      var first = input || (tone === 'danger' && cancel ? cancel : ok);
      first.focus();
      if (input && o.field.select !== false && input.select) input.select();
    });
  }

  // "Excluir este artigo? Ele sai do site." → título "Excluir este artigo?" e o resto como explicação.
  function split(question) {
    var text = String(question || '').trim();
    var at = text.indexOf('? ');
    return at > 0 ? { title: text.slice(0, at + 1), message: text.slice(at + 2) } : { title: text, message: '' };
  }

  function confirm(options) {
    return open(typeof options === 'string' ? split(options) : options);
  }

  function alert(message, options) {
    var o = typeof message === 'string' ? Object.assign({ message: message }, options || {}) : (message || {});
    return open({ title: o.title || 'Atenção', message: o.message, tone: o.tone || 'warning', confirmLabel: o.confirmLabel || 'Entendi', cancelLabel: false }).then(function () {});
  }

  function prompt(options) {
    var o = options || {};
    return open({ title: o.title, message: o.message, tone: o.tone || 'primary', confirmLabel: o.confirmLabel || 'Aplicar', cancelLabel: o.cancelLabel || 'Voltar',
      field: { label: o.label, value: o.value, multiline: !!o.multiline, type: o.type, min: o.min, max: o.max, placeholder: o.placeholder } });
  }

  function copy(label, value) {
    return open({ title: 'Copiar ' + label, tone: 'info', confirmLabel: 'Fechar', cancelLabel: false,
      message: 'O navegador não deixou copiar sozinho. O texto já está selecionado: use Ctrl+C (no iPhone e no Mac, Cmd+C ou toque e segure) e depois Fechar.',
      field: { label: label, value: value, readOnly: true } }).then(function () {});
  }

  // Lê a pergunta de um atributo e os complementos data-confirm-title, -undo ("sim"/"nao"), -undo-text, -action e -tone.
  function ask(element, attribute, fallbackAction) {
    var question = element.getAttribute(attribute);
    if (!question) return Promise.resolve(true);
    var o = split(question);
    var read = function (name) { return element.getAttribute('data-confirm-' + name); };
    if (read('title')) { o.message = question; o.title = read('title'); }
    var undo = read('undo');
    if (undo === 'sim' || undo === 'nao') o.undo = undo === 'sim';
    if (read('undo-text')) o.undoText = read('undo-text');
    o.confirmLabel = read('action') || fallbackAction || 'Confirmar';
    o.tone = read('tone') || (o.undo === false ? 'danger' : 'primary');
    return open(o);
  }

  function buttonText(button) {
    return button ? (button.textContent || button.value || '').replace(/\s+/g, ' ').trim() : '';
  }

  // Formulário com data-cn-confirm, ou botão de envio com data-cn-confirm: pergunta e, com o sim, envia de novo
  // pelo mesmo botão (mantém o name/value do botão, ex.: action=delete).
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    var submitter = event.submitter || null;
    var source = submitter && submitter.hasAttribute('data-cn-confirm') ? submitter : (form.hasAttribute('data-cn-confirm') ? form : null);
    if (!source) return;
    if (form._cnConfirmed) { form._cnConfirmed = false; return; }
    event.preventDefault();
    event.stopImmediatePropagation();
    ask(source, 'data-cn-confirm', buttonText(submitter)).then(function (yes) {
      if (!yes) return;
      form._cnConfirmed = true;
      if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); else form.submit();
    });
  }, true);

  window.ConnectDialog = { confirm: confirm, alert: alert, prompt: prompt, copy: copy, ask: ask };
})();
