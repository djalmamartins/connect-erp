/*
 * Connect — sugestão de pessoa e e-mail enquanto se digita (ADR 0687; pedido do Djalma em 27/11/2026).
 *
 * Componente único do ERP, do Studio e do Help Desk. A partir da 3ª letra, busca no banco (GET /api/v1/suggest/people)
 * e mostra uma lista embaixo do campo; o campo continua livre: quem não está na lista é digitado inteiro.
 *
 *   <input data-cx-suggest="person">                      nome de pessoa (moradores do escopo e equipe)
 *   <input type="email" data-cx-suggest="email">          e-mail
 *   data-cx-suggest-multiple                              vários e-mails separados por vírgula (completa o último)
 *   data-cx-suggest-sources="residents,team"              fontes (padrão: as duas; o servidor confere a permissão)
 *   data-cx-suggest-condominium="#campo" ou "12"          só deste condomínio (seletor de um campo ou número);
 *                                                         sem o atributo, vale o condomínio da barra superior do ERP
 *   data-cx-suggest-unit="#campo" ou "34"                 só desta unidade
 *   data-cx-suggest-fill-email="#campo"                   ao escolher, preenche também o e-mail neste campo
 *   data-cx-suggest-fill-name="#campo"                    ao escolher (campo de e-mail), preenche o nome neste campo
 *   data-cx-suggest-id="#campo"                           guarda o id da conta escolhida (some ao editar o texto)
 *   data-cx-suggest-url="/api/v1/suggest/people"          endereço (padrão: o do <script>, vindo do servidor)
 *
 * Teclado: setas sobem e descem, Enter escolhe, Esc fecha, Tab segue sem escolher. Leitor de tela: combobox
 * com listbox (aria-expanded, aria-activedescendant) e aviso da quantidade encontrada.
 *
 *   ConnectSuggest.enhance(input) / ConnectSuggest.refresh(root)
 *
 * Tela com lista própria já carregada (ex.: síndico e conselho, ADR 0686) usa o mesmo componente, sem a API:
 *   ConnectSuggest.enhance(input, { min: 2, local: function (term) { return [{ name, context, tag, ... }]; },
 *                                   choose: function (item) { ... } })
 */
(function () {
  'use strict';
  if (window.ConnectSuggest) { return; }

  var MIN = 3;
  var WAIT = 250;
  var script = document.currentScript;
  var defaultUrl = (script && script.getAttribute('data-api')) || '/api/v1/suggest/people';
  var fields = new WeakMap();
  var uid = 0;
  var open = null;
  var filling = false;

  var esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  };
  var fold = function (s) {
    s = String(s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  };
  var pick = function (ref, from) {
    if (!ref) { return null; }
    if (/^\d+$/.test(ref)) { return ref; }
    var scope = (from && from.form) || document;
    var node = null;
    try { node = scope.querySelector(ref) || document.querySelector(ref); } catch (e) { node = null; }
    return node;
  };
  var valueOf = function (ref, from) {
    var node = pick(ref, from);
    if (node === null) { return ''; }
    if (typeof node === 'string') { return node; }
    return /^\d+$/.test(String(node.value || '')) ? String(node.value) : '';
  };
  var setValue = function (node, value) {
    if (!node || typeof node === 'string') { return; }
    node.value = value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
  };

  function Field(input, opts) {
    this.input = input;
    this.opts = opts || {};
    this.min = this.opts.min || MIN;
    this.kind = input.getAttribute('data-cx-suggest') === 'email' ? 'email' : 'person';
    this.multiple = input.hasAttribute('data-cx-suggest-multiple');
    this.url = input.getAttribute('data-cx-suggest-url') || defaultUrl;
    this.cache = {};
    this.items = [];
    this.active = -1;
    this.timer = null;
    this.seq = 0;
    this.picked = null;
    this.id = 'cx-suggest-' + (++uid);

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', this.id);

    var self = this;
    input.addEventListener('input', function () { if (filling) { return; } self.typed(); });
    input.addEventListener('keydown', function (e) { self.key(e); });
    input.addEventListener('blur', function () { window.setTimeout(function () { if (document.activeElement !== input) { self.close(); } }, 150); });
    input.addEventListener('focus', function () { if (self.term().length >= self.min && self.items.length) { self.show(); } });
  }

  /* Texto que está sendo buscado: o campo todo, ou o último e-mail da lista. */
  Field.prototype.term = function () {
    var v = this.input.value || '';
    if (this.multiple) { v = v.split(/[,;]/).pop(); }
    return v.replace(/\s+/g, ' ').trim();
  };

  Field.prototype.typed = function () {
    var self = this;
    var idField = pick(this.input.getAttribute('data-cx-suggest-id'), this.input);
    if (idField && typeof idField !== 'string' && this.picked !== null && this.input.value !== this.picked) {
      setValue(idField, '');
      this.picked = null;
    }
    window.clearTimeout(this.timer);
    var term = this.term();
    if (term.length < this.min) { this.items = []; this.close(); return; }
    if (typeof this.opts.local === 'function') {
      var found = this.opts.local(term) || [];
      if (found.length) { this.render(found.slice(0, 8), term); } else { this.items = []; this.close(); }
      return;
    }
    this.timer = window.setTimeout(function () { self.fetch(term); }, WAIT);
  };

  Field.prototype.params = function (term) {
    var p = ['q=' + encodeURIComponent(term), 'kind=' + this.kind, 'limit=8'];
    if (this.kind !== 'email' && (this.input.getAttribute('data-cx-suggest-fill-email') || this.input.getAttribute('data-cx-suggest-with') === 'email')) { p.push('with=email'); }
    var sources = this.input.getAttribute('data-cx-suggest-sources');
    if (sources) { p.push('sources=' + encodeURIComponent(sources)); }
    // Sem condomínio no campo: o da barra superior do ERP, quando há um escolhido.
    var c = valueOf(this.input.getAttribute('data-cx-suggest-condominium'), this.input)
      || (this.input.hasAttribute('data-cx-suggest-condominium') ? '' : (document.body.getAttribute('data-cx-condominium') || ''));
    if (c) { p.push('condominium_id=' + c); }
    var u = valueOf(this.input.getAttribute('data-cx-suggest-unit'), this.input);
    if (u) { p.push('unit_id=' + u); }
    return p.join('&');
  };

  Field.prototype.fetch = function (term) {
    var self = this;
    var query = this.params(term);
    var key = fold(query);
    // Já buscou um começo deste texto e veio menos que o limite: filtra aqui, sem ir ao banco de novo.
    var prefixes = Object.keys(this.cache);
    for (var i = 0; i < prefixes.length; i++) {
      var hit = this.cache[prefixes[i]];
      if (hit.complete && hit.rest === key.replace(/^q=[^&]*/, '') && fold(term).indexOf(hit.term) === 0) {
        var needle = fold(term);
        this.render(hit.items.filter(function (it) { return fold(it.name).indexOf(needle) >= 0 || fold(it.email).indexOf(needle) >= 0; }), term);
        return;
      }
    }
    if (this.cache[key]) { this.render(this.cache[key].items, term); return; }
    var seq = ++this.seq;
    var sep = this.url.indexOf('?') >= 0 ? '&' : '?';
    fetch(this.url + sep + query, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : { data: [], status: r.status }; })
      .then(function (json) {
        var items = Array.isArray(json && json.data) ? json.data : [];
        self.cache[key] = { items: items, term: fold(term), rest: key.replace(/^q=[^&]*/, ''), complete: items.length < 8 };
        if (seq === self.seq && self.term() === term) { self.render(items, term); }
      })
      .catch(function () { /* sem rede: o campo segue livre */ });
  };

  Field.prototype.box = function () {
    if (this.node) { return this.node; }
    var self = this;
    var node = document.createElement('div');
    node.className = 'cx-suggest';
    node.innerHTML = '<ul class="cx-suggest__list" role="listbox" id="' + this.id + '"></ul><p class="cx-suggest__hint"></p><p class="cx-visually-hidden" aria-live="polite"></p>';
    node.addEventListener('mousedown', function (e) { e.preventDefault(); });
    node.addEventListener('click', function (e) {
      var li = e.target.closest('[data-index]');
      if (li) { self.choose(+li.getAttribute('data-index')); }
    });
    this.node = node;
    return node;
  };

  Field.prototype.render = function (items, term) {
    this.items = items;
    this.active = -1;
    var node = this.box();
    var list = node.querySelector('.cx-suggest__list');
    var self = this;
    var needle = fold(term);
    var mark = function (text) {
      var t = String(text || '');
      var at = fold(t).indexOf(needle);
      if (at < 0 || needle === '') { return esc(t); }
      return esc(t.slice(0, at)) + '<mark>' + esc(t.slice(at, at + term.length)) + '</mark>' + esc(t.slice(at + term.length));
    };
    list.innerHTML = items.map(function (it, i) {
      var main = self.kind === 'email' ? (it.email || it.name) : it.name;
      var sub = self.kind === 'email' ? [it.name, it.context].filter(Boolean).join(' · ') : [it.email, it.context].filter(Boolean).join(' · ');
      return '<li class="cx-suggest__item" role="option" id="' + self.id + '-' + i + '" data-index="' + i + '" aria-selected="false">'
        + '<span class="cx-suggest__avatar" aria-hidden="true">' + esc((it.name || '?').trim().charAt(0).toUpperCase()) + '</span>'
        + '<span class="cx-suggest__text"><strong>' + mark(main) + '</strong>' + (sub ? '<small>' + mark(sub) + '</small>' : '') + '</span>'
        + (it.tag || it.source === 'team' ? '<span class="cx-suggest__tag">' + esc(it.tag || 'Equipe') + '</span>' : '') + '</li>';
    }).join('');
    node.querySelector('.cx-suggest__hint').textContent = items.length
      ? 'Não é ninguém da lista? Continue digitando normalmente.'
      : 'Ninguém encontrado com "' + term + '". Pode digitar tudo normalmente.';
    node.querySelector('[aria-live]').textContent = items.length ? items.length + (items.length === 1 ? ' sugestão' : ' sugestões') + '. Use as setas para escolher.' : '';
    this.show();
  };

  Field.prototype.show = function () {
    var node = this.box();
    if (!node.parentNode) { (this.input.closest('dialog[open]') || document.body).appendChild(node); }
    if (open && open !== this) { open.close(); }
    open = this;
    node.hidden = false;
    this.input.setAttribute('aria-expanded', 'true');
    this.position();
  };

  Field.prototype.close = function () {
    if (this.node) { this.node.hidden = true; }
    this.input.setAttribute('aria-expanded', 'false');
    this.input.removeAttribute('aria-activedescendant');
    if (open === this) { open = null; }
  };

  Field.prototype.position = function () {
    if (!this.node || this.node.hidden) { return; }
    var r = this.input.getBoundingClientRect();
    var node = this.node;
    var vw = document.documentElement.clientWidth;
    node.style.width = Math.max(260, Math.min(r.width, vw - 16)) + 'px';
    var left = Math.max(8, Math.min(r.left, vw - node.offsetWidth - 8));
    var top = r.bottom + 4;
    if (top + node.offsetHeight > window.innerHeight - 8 && r.top - node.offsetHeight - 4 > 8) { top = r.top - node.offsetHeight - 4; }
    node.style.left = left + 'px';
    node.style.top = top + 'px';
  };

  Field.prototype.move = function (step) {
    if (!this.items.length) { return; }
    this.active = (this.active + step + this.items.length) % this.items.length;
    var self = this;
    Array.prototype.forEach.call(this.node.querySelectorAll('[data-index]'), function (li, i) {
      li.setAttribute('aria-selected', i === self.active ? 'true' : 'false');
      li.classList.toggle('is-active', i === self.active);
      if (i === self.active && li.scrollIntoView) { li.scrollIntoView({ block: 'nearest' }); }
    });
    this.input.setAttribute('aria-activedescendant', this.id + '-' + this.active);
  };

  Field.prototype.key = function (e) {
    var visible = this.node && !this.node.hidden && this.items.length;
    if (e.key === 'ArrowDown') { if (visible) { e.preventDefault(); this.move(1); } else if (this.items.length) { this.show(); } }
    else if (e.key === 'ArrowUp') { if (visible) { e.preventDefault(); this.move(-1); } }
    else if (e.key === 'Enter') { if (visible && this.active >= 0) { e.preventDefault(); this.choose(this.active); } }
    else if (e.key === 'Escape') { if (this.node && !this.node.hidden) { e.preventDefault(); e.stopPropagation(); this.close(); } }
    else if (e.key === 'Tab') { this.close(); }
  };

  Field.prototype.choose = function (index) {
    var it = this.items[index];
    if (!it) { return; }
    var input = this.input;
    if (typeof this.opts.choose === 'function') {
      this.close();
      this.opts.choose(it);
      this.picked = input.value;
      return;
    }
    var text = this.kind === 'email' ? (it.email || '') : it.name;
    if (this.multiple) {
      var parts = input.value.split(/[,;]/).map(function (s) { return s.trim(); }).filter(Boolean);
      parts.pop();
      if (parts.indexOf(text) < 0) { parts.push(text); }
      text = parts.join(', ') + ', ';
    }
    filling = true;
    setValue(input, text);
    var email = pick(input.getAttribute('data-cx-suggest-fill-email'), input);
    if (email && it.email) { setValue(email, it.email); }
    var name = pick(input.getAttribute('data-cx-suggest-fill-name'), input);
    if (name && it.name) { setValue(name, it.name); }
    var idField = pick(input.getAttribute('data-cx-suggest-id'), input);
    if (idField && typeof idField !== 'string') { setValue(idField, it.account_id ? String(it.account_id) : ''); }
    filling = false;
    this.picked = input.value;
    this.close();
    input.focus();
    input.dispatchEvent(new CustomEvent('cx-suggest:choose', { bubbles: true, detail: it }));
  };

  var enhance = function (input, opts) {
    if (!input || fields.has(input) || input.tagName !== 'INPUT') { return null; }
    var f = new Field(input, opts);
    fields.set(input, f);
    return f;
  };
  var refresh = function (root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[data-cx-suggest]'), enhance);
  };

  window.addEventListener('resize', function () { if (open) { open.position(); } });
  window.addEventListener('scroll', function () { if (open) { open.position(); } }, true);
  document.addEventListener('mousedown', function (e) { if (open && e.target !== open.input && !(open.node && open.node.contains(e.target))) { open.close(); } });

  window.ConnectSuggest = { enhance: enhance, refresh: refresh };
  var start = function () {
    refresh(document);
    if (window.MutationObserver) {
      new MutationObserver(function (list) {
        list.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (n.nodeType !== 1) { return; }
            if (n.matches && n.matches('input[data-cx-suggest]')) { enhance(n); }
            else if (n.querySelectorAll) { refresh(n); }
          });
        });
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
