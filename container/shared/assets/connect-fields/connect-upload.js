/*
 * Connect — envio de arquivos padrão (ADR 0628).
 *
 * Melhora qualquer <input type="file"> do sistema (ERP, Studio, Help Desk, apps web) sem trocar o
 * campo: o input original continua no formulário, com o mesmo name, id e validação, e recebe os
 * arquivos escolhidos. Em volta dele aparecem:
 *   - a área de arrastar e soltar com o botão "Escolher arquivo" e o limite de tamanho visível;
 *   - a lista com um cartão por arquivo: nome, tamanho, barra de progresso, remover e, quando o
 *     envio falha, o aviso "Falha no envio" com o botão "Tentar de novo".
 *
 * Para desligar num campo: data-cx-upload="off". Limite: data-max-bytes (bytes) ou data-max-mb;
 * sem nenhum dos dois vale 20 MB. Texto da área: data-cx-upload-label.
 *
 * Envio: formulário comum (POST multipart sem script próprio) passa a ser enviado em segundo plano
 * com progresso arquivo por arquivo; ao terminar a página mostra a resposta do servidor como antes.
 * Formulários com envio próprio (Disco Virtual, Studio, anexos da receita) chamam a API abaixo para
 * pintar o progresso. Para manter o envio do navegador: data-cx-upload-submit="native" no <form>.
 *
 *   ConnectUpload.enhance(input)              melhora um campo (o init já faz isso sozinho)
 *   ConnectUpload.files(input)                arquivos válidos escolhidos
 *   ConnectUpload.progress(input, file, pct)  pinta o progresso de um arquivo (0–100)
 *   ConnectUpload.done(input, file)           marca como enviado
 *   ConnectUpload.fail(input, file, msg, retry) marca a falha; retry() é chamado no "Tentar de novo"
 *   ConnectUpload.clear(input)                limpa a lista e o campo
 *   ConnectUpload.formProgress(form, loaded, total) / formDone(form, clear) / formReady(form) / formFail(form, msg, retry)
 *
 * Sem dependências e sem alert() do navegador (ADR 0316/0320).
 */
(function () {
  'use strict';
  if (window.ConnectUpload) { return; }

  var DEFAULT_MAX = 20 * 1024 * 1024;
  var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  var states = new WeakMap();
  var uid = 0;

  var ICON_DOC = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14 3v5h5M9 13h6M9 17h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var ICON_IMAGE = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="4.5" width="17" height="15" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="1.7" fill="currentColor"/><path d="m4.5 17.5 5-5 4 4 2.5-2.5 3.5 3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
  var ICON_UP = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 15V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var ICON_X = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
  var ICON_RETRY = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 12a8 8 0 0 1-14.3 4.9M4 12A8 8 0 0 1 18.3 7.1M18.5 3v4.4h-4.4M5.5 21v-4.4h4.4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var ICON_OK = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  var size = function (bytes) {
    if (bytes < 1024) { return bytes + ' B'; }
    if (bytes < 1048576) { return Math.round(bytes / 1024) + ' KB'; }
    var mb = bytes / 1048576;
    return (mb >= 10 ? Math.round(mb) : mb.toFixed(1).replace('.', ',').replace(',0', '')) + ' MB';
  };

  var maxBytes = function (input) {
    var bytes = parseInt(input.getAttribute('data-max-bytes'), 10);
    if (bytes > 0) { return bytes; }
    var mb = parseFloat(input.getAttribute('data-max-mb'));
    if (mb > 0) { return Math.round(mb * 1048576); }
    var meta = document.querySelector('meta[name="cx-upload-max-bytes"]');
    bytes = meta ? parseInt(meta.getAttribute('content'), 10) : 0;
    return bytes > 0 ? bytes : DEFAULT_MAX;
  };

  var acceptList = function (input) {
    return (input.getAttribute('accept') || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
  };

  var accepted = function (input, file) {
    var list = acceptList(input);
    if (!list.length) { return true; }
    var name = file.name.toLowerCase();
    var type = (file.type || '').toLowerCase();
    return list.some(function (rule) {
      if (rule.charAt(0) === '.') { return name.slice(-rule.length) === rule; }
      if (rule.slice(-2) === '/*') { return type.indexOf(rule.slice(0, -1)) === 0; }
      return type === rule;
    });
  };

  var MIME_NAMES = { 'image/jpeg': 'JPG', 'image/png': 'PNG', 'image/webp': 'WEBP', 'image/gif': 'GIF', 'image/svg+xml': 'SVG', 'application/pdf': 'PDF', 'image/*': 'imagem', 'video/*': 'vídeo', 'audio/*': 'áudio' };
  var acceptLabel = function (input) {
    var out = [];
    acceptList(input).forEach(function (rule) {
      var label = rule.charAt(0) === '.' ? rule.slice(1).toUpperCase() : (MIME_NAMES[rule] || '');
      if (label === 'JPEG') { label = 'JPG'; }
      if (label && out.indexOf(label) < 0) { out.push(label); }
    });
    if (out.length > 1) { return out.slice(0, -1).join(', ') + ' ou ' + out[out.length - 1]; }
    return out[0] || '';
  };

  var isImage = function (file) { return /^image\/(jpeg|png|webp|gif)$/.test(file.type || ''); };

  var el = function (tag, cls, html) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (html !== undefined) { node.innerHTML = html; }
    return node;
  };

  var sameFile = function (a, b) { return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified; };

  /* Coloca no input só os arquivos válidos, na ordem da lista. */
  var writeFiles = function (state) {
    var input = state.input;
    try {
      var dt = new DataTransfer();
      state.items.forEach(function (item) { if (!item.error) { dt.items.add(item.file); } });
      state.writing = true;
      input.files = dt.files;
    } catch (e) {
      /* Navegador antigo sem DataTransfer: fica o que o navegador escolheu. */
    } finally {
      state.writing = false;
    }
  };

  var notify = function (state) {
    state.silent = true;
    state.input.dispatchEvent(new Event('input', { bubbles: true }));
    state.input.dispatchEvent(new Event('change', { bubbles: true }));
    state.silent = false;
  };

  var validate = function (state, file) {
    var max = maxBytes(state.input);
    if (file.size > max) { return 'Passa do limite de ' + size(max) + '.'; }
    if (file.size < 1) { return 'O arquivo está vazio.'; }
    if (!accepted(state.input, file)) { return 'Tipo não aceito' + (acceptLabel(state.input) ? ': envie ' + acceptLabel(state.input) + '.' : '.'); }
    return '';
  };

  /* Junta a escolha nova com a lista (campo múltiplo) ou troca (campo simples). */
  var take = function (state, list) {
    var files = Array.prototype.slice.call(list || []);
    if (!files.length) { return false; }
    if (!state.input.multiple) {
      files = files.slice(0, 1);
      state.items.forEach(dropItem);
      state.items = [];
    }
    files.forEach(function (file) {
      if (state.items.some(function (item) { return sameFile(item.file, file); })) { return; }
      state.items.push({ file: file, error: validate(state, file), status: 'ready', pct: 0 });
    });
    writeFiles(state);
    render(state);
    var bad = state.items.filter(function (item) { return item.error; });
    if (bad.length) { announce(state, bad[bad.length - 1].file.name + ': ' + bad[bad.length - 1].error); }
    /* data-cx-upload-auto no <form>: começa a enviar assim que os arquivos são escolhidos. */
    var form = state.input.form;
    if (form && form.hasAttribute('data-cx-upload-auto') && state.items.some(function (it) { return !it.error && it.status === 'ready'; })) {
      window.setTimeout(function () { if (form.requestSubmit) { form.requestSubmit(); } }, 0);
    }
    return true;
  };

  var dropItem = function (item) { if (item.preview) { URL.revokeObjectURL(item.preview); } };

  var announce = function (state, text) {
    state.live.textContent = '';
    window.setTimeout(function () { state.live.textContent = text; }, 30);
  };

  var statusText = function (item) {
    if (item.error) { return item.error; }
    if (item.status === 'uploading') { return item.pct >= 100 ? 'Concluindo…' : 'Enviando… ' + item.pct + '%'; }
    if (item.status === 'done') { return 'Enviado'; }
    if (item.status === 'failed') { return item.message || 'Falha no envio'; }
    return 'Pronto para enviar';
  };

  var paintItem = function (state, item) {
    var li = item.node;
    if (!li) { return; }
    var failed = item.error || item.status === 'failed';
    li.className = 'cx-upload__item is-' + (item.error ? 'invalid' : item.status);
    var bar = li.querySelector('.cx-upload__bar');
    bar.hidden = !(item.status === 'uploading' || item.status === 'done');
    bar.setAttribute('aria-valuenow', String(item.pct));
    bar.firstChild.style.width = item.pct + '%';
    var status = li.querySelector('.cx-upload__status');
    status.textContent = statusText(item);
    li.querySelector('.cx-upload__remove').hidden = item.status === 'uploading' || item.status === 'done';
    li.querySelector('.cx-upload__retry').hidden = !(item.status === 'failed' && typeof item.retry === 'function');
    li.querySelector('.cx-upload__ok').hidden = item.status !== 'done';
    li.setAttribute('aria-invalid', failed ? 'true' : 'false');
  };

  var render = function (state) {
    var list = state.list;
    list.innerHTML = '';
    state.items.forEach(function (item, index) {
      var li = el('li', '');
      var thumb = el('span', 'cx-upload__thumb', isImage(item.file) ? '' : ICON_DOC);
      if (isImage(item.file) && window.URL && URL.createObjectURL) {
        if (!item.preview) { item.preview = URL.createObjectURL(item.file); }
        var img = el('img');
        img.src = item.preview;
        img.alt = '';
        img.addEventListener('error', function () { thumb.innerHTML = ICON_IMAGE; });
        thumb.appendChild(img);
      } else if (isImage(item.file)) {
        thumb.innerHTML = ICON_IMAGE;
      }
      var info = el('div', 'cx-upload__info');
      var name = el('span', 'cx-upload__name');
      name.textContent = item.file.name;
      name.title = item.file.name;
      var meta = el('span', 'cx-upload__size');
      meta.textContent = size(item.file.size);
      var bar = el('div', 'cx-upload__bar');
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-label', 'Envio de ' + item.file.name);
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      bar.appendChild(el('span'));
      var status = el('span', 'cx-upload__status');
      info.appendChild(name);
      info.appendChild(meta);
      info.appendChild(bar);
      info.appendChild(status);
      var actions = el('div', 'cx-upload__actions');
      var ok = el('span', 'cx-upload__ok', ICON_OK);
      var retry = el('button', 'cx-upload__retry', ICON_RETRY);
      retry.type = 'button';
      retry.setAttribute('aria-label', 'Tentar de novo: ' + item.file.name);
      retry.title = 'Tentar de novo';
      retry.addEventListener('click', function () {
        if (typeof item.retry === 'function') {
          var again = item.retry;
          state.items.forEach(function (it) { if (it.status === 'failed') { it.status = 'ready'; it.message = ''; it.pct = 0; paintItem(state, it); } });
          again();
        }
      });
      var remove = el('button', 'cx-upload__remove', ICON_X);
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Remover ' + item.file.name);
      remove.title = 'Remover';
      remove.addEventListener('click', function () {
        dropItem(item);
        state.items.splice(state.items.indexOf(item), 1);
        writeFiles(state);
        render(state);
        notify(state);
        announce(state, item.file.name + ' removido.');
        state.button.focus();
      });
      actions.appendChild(ok);
      actions.appendChild(retry);
      actions.appendChild(remove);
      li.appendChild(thumb);
      li.appendChild(info);
      li.appendChild(actions);
      item.node = li;
      list.appendChild(li);
      paintItem(state, item);
      li.setAttribute('data-index', String(index));
    });
    list.hidden = state.items.length === 0;
    state.root.classList.toggle('has-files', state.items.length > 0);
  };

  var findItem = function (state, file) {
    if (typeof file === 'number') { return state.items.filter(function (it) { return !it.error; })[file] || null; }
    for (var i = 0; i < state.items.length; i++) { if (state.items[i].file === file || (file && sameFile(state.items[i].file, file))) { return state.items[i]; } }
    return null;
  };

  var enhance = function (input) {
    if (!input || input.type !== 'file' || states.has(input) || input.getAttribute('data-cx-upload') === 'off') { return null; }
    if (input.closest('[data-cx-upload="off"]')) { return null; }
    var id = input.id || ('cx-file-' + (++uid));
    input.id = id;

    var root = el('div', 'cx-upload' + (input.multiple ? ' cx-upload--multiple' : '') + (input.getAttribute('data-cx-upload-size') === 'compact' ? ' cx-upload--compact' : ''));
    root.setAttribute('data-cx-upload-root', '');
    var zone = el('div', 'cx-upload__zone');
    var label = input.getAttribute('data-cx-upload-label') || (input.multiple ? 'Arraste e solte os arquivos aqui ou' : 'Arraste e solte o arquivo aqui ou');
    var limit = 'Tamanho máximo: ' + size(maxBytes(input)) + (input.multiple ? ' por arquivo' : '');
    var kinds = acceptLabel(input);
    zone.innerHTML = '<span class="cx-upload__icon">' + ICON_DOC + '</span>' +
      '<span class="cx-upload__text"></span>' +
      '<button type="button" class="cx-upload__button">' + ICON_UP + '<span></span></button>' +
      '<span class="cx-upload__hint"></span>';
    zone.querySelector('.cx-upload__text').textContent = label;
    var button = zone.querySelector('.cx-upload__button');
    button.querySelector('span').textContent = input.multiple ? 'Escolher arquivos' : 'Escolher arquivo';
    var hintId = id + '-cx-hint';
    var hint = zone.querySelector('.cx-upload__hint');
    hint.id = hintId;
    hint.textContent = limit + (kinds ? ' · ' + kinds : '');

    var list = el('ul', 'cx-upload__list');
    list.setAttribute('aria-label', 'Arquivos escolhidos');
    list.hidden = true;
    var live = el('span', 'cx-visually-hidden');
    live.setAttribute('aria-live', 'polite');

    /* O input fica dentro do componente, escondido só da vista (continua no formulário e validável). */
    var host = input.parentNode;
    var labelEl = input.closest('label');
    var crowded = labelEl && Array.prototype.some.call(labelEl.querySelectorAll('input, select, textarea, button'), function (n) { return n !== input && n.type !== 'hidden'; });
    if (crowded) {
      /* Rótulo com outros campos (ex.: endereço + arquivo): o componente fica no lugar do input. */
      host.insertBefore(root, input);
      labelEl = null;
    } else if (labelEl) {
      /* Campo dentro de <label>: o componente vai logo depois do rótulo, que passa a apontar para o input. */
      labelEl.parentNode.insertBefore(root, labelEl.nextSibling);
      labelEl.setAttribute('for', id);
      labelEl.classList.add('cx-upload-label');
      labelEl.querySelectorAll('[data-cx-upload-hide]').forEach(function (n) { n.hidden = true; });
    } else {
      host.insertBefore(root, input);
    }
    root.appendChild(zone);
    root.appendChild(list);
    root.appendChild(live);
    zone.appendChild(input);
    input.classList.add('cx-upload__native');
    input.setAttribute('tabindex', '-1');
    var described = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
    if (described.indexOf(hintId) < 0) { described.push(hintId); }
    button.setAttribute('aria-describedby', described.join(' '));
    var labelText = labelEl ? labelEl.textContent.replace(/\s+/g, ' ').trim() : '';
    var forLabel = !labelEl && document.querySelector('label[for="' + id + '"]');
    if (forLabel) { labelText = forLabel.textContent.replace(/\s+/g, ' ').trim(); }
    if (!labelText) { labelText = (input.getAttribute('aria-label') || '').trim(); }
    if (labelText) { button.setAttribute('aria-label', button.textContent.trim() + ': ' + labelText); }

    var state = { input: input, root: root, zone: zone, list: list, live: live, button: button, items: [], writing: false, silent: false };
    states.set(input, state);

    button.addEventListener('click', function () { if (!input.disabled) { input.click(); } });
    /* Quem manda o foco para o campo (validação, atalhos) cai no botão visível. */
    input.addEventListener('focus', function () { button.focus(); });
    zone.addEventListener('click', function (event) {
      if (!event.target.closest('button')) { event.preventDefault(); }
      if (event.target === zone || event.target.classList.contains('cx-upload__text') || event.target.closest('.cx-upload__icon')) { if (!input.disabled) { input.click(); } }
    });
    var depth = 0;
    zone.addEventListener('dragenter', function (event) { event.preventDefault(); depth++; zone.classList.add('is-over'); });
    zone.addEventListener('dragover', function (event) { event.preventDefault(); if (event.dataTransfer) { event.dataTransfer.dropEffect = 'copy'; } });
    zone.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) { zone.classList.remove('is-over'); } });
    zone.addEventListener('drop', function (event) {
      event.preventDefault();
      depth = 0;
      zone.classList.remove('is-over');
      if (input.disabled || !event.dataTransfer) { return; }
      if (take(state, event.dataTransfer.files)) { notify(state); }
    });

    /* Quem zera o campo por script (input.value = '') também limpa a lista. */
    try {
      Object.defineProperty(input, 'value', {
        configurable: true,
        get: function () { return proto.get.call(input); },
        set: function (v) {
          proto.set.call(input, v);
          if (!state.writing && (v === '' || v === null)) { state.items.forEach(dropItem); state.items = []; render(state); }
        }
      });
    } catch (e) { /* segue sem o atalho */ }

    if (input.files && input.files.length) { take(state, input.files); }
    return state;
  };

  /* Escolha pela janela do sistema: corrige a lista antes de os outros scripts lerem o campo. */
  window.addEventListener('change', function (event) {
    var input = event.target;
    var state = input && states.get(input);
    if (!state || state.silent || state.writing) { return; }
    var chosen = Array.prototype.slice.call(input.files || []);
    if (!chosen.length) {
      /* Cancelou a janela: o navegador esvaziou o campo; devolve a escolha anterior. */
      if (state.items.length) { writeFiles(state); }
      return;
    }
    take(state, chosen);
  }, true);

  var formStates = function (form) {
    var out = [];
    Array.prototype.forEach.call(form.querySelectorAll('input[type="file"]'), function (input) {
      var state = states.get(input);
      if (state) { out.push(state); }
    });
    return out;
  };

  var pending = function (form) {
    var items = [];
    formStates(form).forEach(function (state) {
      if (!state.input.name || state.input.disabled) { return; }
      state.items.forEach(function (item) { if (!item.error) { items.push({ state: state, item: item }); } });
    });
    return items;
  };

  var api = {
    enhance: function (input) { return !!enhance(input); },
    refresh: function (root) {
      Array.prototype.forEach.call((root || document).querySelectorAll('input[type="file"]'), enhance);
    },
    files: function (input) {
      var state = states.get(input);
      if (!state) { return Array.prototype.slice.call(input.files || []); }
      return state.items.filter(function (item) { return !item.error; }).map(function (item) { return item.file; });
    },
    progress: function (input, file, pct) {
      var state = states.get(input);
      var item = state && findItem(state, file);
      if (!item) { return; }
      item.status = 'uploading';
      item.pct = Math.max(0, Math.min(100, Math.round(pct)));
      paintItem(state, item);
    },
    done: function (input, file) {
      var state = states.get(input);
      var item = state && findItem(state, file);
      if (!item) { return; }
      item.status = 'done';
      item.pct = 100;
      paintItem(state, item);
    },
    fail: function (input, file, message, retry) {
      var state = states.get(input);
      var item = state && findItem(state, file);
      if (!item) { return; }
      item.status = 'failed';
      item.message = message || 'Falha no envio';
      item.retry = retry || null;
      paintItem(state, item);
      announce(state, item.file.name + ': ' + item.message);
    },
    clear: function (input) {
      var state = states.get(input);
      if (!state) { proto.set.call(input, ''); return; }
      state.items.forEach(dropItem);
      state.items = [];
      writeFiles(state);
      render(state);
    },
    /* Progresso de um envio único com vários arquivos: as partes do multipart saem em ordem, então
       a fração enviada diz em que arquivo o envio está. */
    formProgress: function (form, loaded, total) {
      var list = pending(form);
      if (!list.length || !total) { return; }
      var sum = list.reduce(function (acc, p) { return acc + p.item.file.size; }, 0) || 1;
      var overhead = Math.max(0, total - sum);
      var sent = Math.max(0, loaded - overhead / 2);
      var before = 0;
      list.forEach(function (p) {
        var s = p.item.file.size || 1;
        var pct = Math.max(0, Math.min(1, (sent - before) / s));
        before += s;
        p.item.status = 'uploading';
        p.item.pct = Math.round((loaded >= total ? 1 : pct) * 100);
        paintItem(p.state, p.item);
      });
    },
    formDone: function (form, clear) {
      pending(form).forEach(function (p) { p.item.status = 'done'; p.item.pct = 100; paintItem(p.state, p.item); });
      if (clear) {
        window.setTimeout(function () { formStates(form).forEach(function (state) { api.clear(state.input); }); }, 1200);
      }
    },
    formReady: function (form) {
      pending(form).forEach(function (p) { p.item.status = 'ready'; p.item.pct = 0; paintItem(p.state, p.item); });
    },
    formFail: function (form, message, retry) {
      pending(form).forEach(function (p) {
        p.item.status = 'failed';
        p.item.message = message || 'Falha no envio';
        p.item.retry = retry || null;
        paintItem(p.state, p.item);
      });
      var first = formStates(form)[0];
      if (first) { announce(first, message || 'Falha no envio'); }
    }
  };

  /* Envio em segundo plano dos formulários comuns que têm arquivo. Fica no window e na fase de
     bolha: se algum script do próprio formulário já cancelou o envio, ele cuida do resto. */
  var busyButtons = function (form, on) {
    Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'), function (b) {
      if (on) { b.setAttribute('data-cx-was-disabled', b.disabled ? '1' : '0'); b.disabled = true; }
      else if (b.hasAttribute('data-cx-was-disabled')) { b.disabled = b.getAttribute('data-cx-was-disabled') === '1'; b.removeAttribute('data-cx-was-disabled'); }
    });
    form.setAttribute('aria-busy', on ? 'true' : 'false');
  };

  var download = function (blob, disposition) {
    var match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition || '');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = match ? decodeURIComponent(match[1]) : 'arquivo';
    document.body.appendChild(a);
    a.click();
    window.setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
  };

  var send = function (form, submitter) {
    var data;
    try { data = submitter ? new FormData(form, submitter) : new FormData(form); } catch (e) {
      data = new FormData(form);
      if (submitter && submitter.name) { data.append(submitter.name, submitter.value); }
    }
    var action = (submitter && submitter.getAttribute('formaction')) || form.getAttribute('action') || window.location.href;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', action);
    xhr.responseType = 'blob';
    busyButtons(form, true);
    var retry = function () { send(form, submitter); };
    xhr.upload.addEventListener('progress', function (event) {
      if (event.lengthComputable) { api.formProgress(form, event.loaded, event.total); }
    });
    xhr.onerror = function () {
      busyButtons(form, false);
      api.formFail(form, 'Falha no envio: sem conexão com o servidor.', retry);
    };
    xhr.onload = function () {
      if (xhr.status === 413) { busyButtons(form, false); api.formFail(form, 'Falha no envio: passa do limite do servidor.', retry); return; }
      if (xhr.status >= 500 || xhr.status === 0 || xhr.status === 408 || xhr.status === 429) {
        busyButtons(form, false);
        api.formFail(form, 'Falha no envio (erro ' + xhr.status + ').', retry);
        return;
      }
      var type = xhr.getResponseHeader('Content-Type') || '';
      var disposition = xhr.getResponseHeader('Content-Disposition') || '';
      api.formDone(form, false);
      if (/attachment/i.test(disposition) || !/text\/html|application\/xhtml/i.test(type)) {
        busyButtons(form, false);
        if (/json/i.test(type) && !/attachment/i.test(disposition)) {
          /* Resposta JSON (API): segue para a página indicada ou recarrega. */
          xhr.response.text().then(function (text) {
            var json = {};
            try { json = JSON.parse(text); } catch (e) { json = {}; }
            if (json.redirect) { window.location.href = json.redirect; } else { window.location.reload(); }
          });
          return;
        }
        download(xhr.response, disposition);
        return;
      }
      xhr.response.text().then(function (html) {
        var url = xhr.responseURL || action;
        try {
          if (new URL(url, window.location.href).origin === window.location.origin) { window.history.replaceState(null, '', url); }
        } catch (e) { /* mantém o endereço */ }
        document.open();
        document.write(html);
        document.close();
      });
    };
    xhr.send(data);
  };

  /* data-cx-upload-queue no <form>: um envio por arquivo, em fila, cada um com a sua barra; o que
     falhar fica na lista com "Tentar de novo" e os que deram certo não são enviados de novo. */
  var stripTags = function (html) { var d = document.createElement('div'); d.innerHTML = html || ''; return (d.textContent || '').replace(/\s+/g, ' ').trim(); };
  var sendQueue = function (form, submitter) {
    var list = pending(form).filter(function (p) { return p.item.status !== 'done'; });
    var action = (submitter && submitter.getAttribute('formaction')) || form.getAttribute('action') || window.location.href;
    var failed = 0;
    var last = null;
    var retry = function () { sendQueue(form, submitter); };
    busyButtons(form, true);
    var finish = function () {
      busyButtons(form, false);
      if (failed) { return; }
      if (last && last.redirect) { window.location.href = last.redirect; return; }
      window.setTimeout(function () { window.location.reload(); }, 500);
    };
    var next = function (i) {
      if (i >= list.length) { finish(); return; }
      var p = list[i];
      var data;
      try { data = submitter ? new FormData(form, submitter) : new FormData(form); } catch (e) { data = new FormData(form); }
      formStates(form).forEach(function (st) { if (st.input.name) { data.delete(st.input.name); } });
      data.append(p.state.input.name, p.item.file);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', action);
      xhr.setRequestHeader('Accept', 'application/json, text/html');
      api.progress(p.state.input, p.item.file, 0);
      xhr.upload.addEventListener('progress', function (event) { if (event.lengthComputable) { api.progress(p.state.input, p.item.file, event.loaded / event.total * 100); } });
      xhr.onerror = function () { failed++; api.fail(p.state.input, p.item.file, 'Falha no envio: sem conexão com o servidor.', retry); next(i + 1); };
      xhr.onload = function () {
        var json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
        var problem = '';
        if (xhr.status === 413) { problem = 'Falha no envio: passa do limite do servidor.'; }
        else if (xhr.status >= 400) { problem = (json && json.message ? stripTags(json.message) : '') || 'Falha no envio (erro ' + xhr.status + ').'; }
        else if (json && json.message && !json.reload && !json.redirect) { problem = stripTags(json.message) || 'Falha no envio.'; }
        if (problem) { failed++; api.fail(p.state.input, p.item.file, problem, retry); }
        else { api.done(p.state.input, p.item.file); last = json || last; }
        next(i + 1);
      };
      xhr.send(data);
    };
    next(0);
  };

  window.addEventListener('submit', function (event) {
    var form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement)) { return; }
    if (form.getAttribute('data-cx-upload-submit') === 'native') { return; }
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
    if (!pending(form).length || !window.FormData || !window.XMLHttpRequest) { return; }
    var target = (event.submitter && event.submitter.getAttribute('formtarget')) || form.getAttribute('target');
    if (target && target !== '_self') { return; }
    event.preventDefault();
    if (form.hasAttribute('data-cx-upload-queue')) { sendQueue(form, event.submitter || null); return; }
    send(form, event.submitter || null);
  });

  window.ConnectUpload = api;

  var start = function () {
    api.refresh(document);
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (r) {
          Array.prototype.forEach.call(r.addedNodes, function (n) {
            if (n.nodeType !== 1) { return; }
            if (n.matches && n.matches('input[type="file"]')) { enhance(n); } else if (n.querySelectorAll) { api.refresh(n); }
          });
        });
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
