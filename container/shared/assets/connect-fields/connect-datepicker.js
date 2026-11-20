/*
 * Connect — seletor de datas e de período padrão (ADR 0624).
 *
 * Melhora os <input type="date"> e <input type="datetime-local"> do sistema sem trocar o campo: o
 * input original continua no formulário com o mesmo name, id e valor (AAAA-MM-DD), escondido só da
 * vista. No lugar aparece um campo "dd/mm/aaaa" (dá para digitar) com o botão do calendário.
 *
 * Período: dois campos do mesmo formulário viram um par (início e fim) e abrem um calendário de dois
 * meses: o primeiro clique marca o início, o segundo o fim, e os dias do meio ficam destacados.
 * Pares reconhecidos pelo nome (de/ate, date_from/date_to, from/to, inicio/fim, start_date/end_date,
 * starts_on/ends_on, starts_at/ends_at, expected_on/expected_until, entry_on/exit_on) ou marcados à
 * mão com data-cx-range="inicio" e data-cx-range="fim" (+ data-cx-range-group quando houver vários).
 *
 * Para desligar num campo: data-cx-date="off". Respeita min, max, required e disabled. Teclado: setas
 * movem o dia, PageUp/PageDown trocam o mês, Home/End vão ao começo/fim da semana, Enter escolhe e
 * Esc fecha. Quem muda o valor por script (input.value = …) atualiza o campo visível.
 *
 *   ConnectDatepicker.enhance(input) / ConnectDatepicker.refresh(root)
 */
(function () {
  'use strict';
  if (window.ConnectDatepicker) { return; }

  var MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  var DAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  var DAYS_LONG = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
  var PAIRS = [['de', 'ate'], ['date_from', 'date_to'], ['from', 'to'], ['inicio', 'fim'], ['start_date', 'end_date'], ['starts_on', 'ends_on'], ['starts_at', 'ends_at'], ['expected_on', 'expected_until'], ['entry_on', 'exit_on'], ['data_inicio', 'data_fim'], ['periodo_de', 'periodo_ate']];
  var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  var fields = new WeakMap();
  var uid = 0;
  var openPicker = null;

  var ICON_CAL = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 10h17M8 3v4M16 3v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
  var ICON_PREV = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m14.5 6-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var ICON_NEXT = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  /* Datas como texto AAAA-MM-DD (sem fuso: o calendário é de dias, não de instantes). */
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var iso = function (y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); };
  var parts = function (s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    return m ? { y: +m[1], m: +m[2] - 1, d: +m[3] } : null;
  };
  var valid = function (y, m, d) { var t = new Date(y, m, d); return t.getFullYear() === y && t.getMonth() === m && t.getDate() === d; };
  var addDays = function (s, n) { var p = parts(s); var t = new Date(p.y, p.m, p.d + n); return iso(t.getFullYear(), t.getMonth(), t.getDate()); };
  var addMonths = function (s, n) {
    var p = parts(s);
    var last = new Date(p.y, p.m + n + 1, 0).getDate();
    var t = new Date(p.y, p.m + n, Math.min(p.d, last));
    return iso(t.getFullYear(), t.getMonth(), t.getDate());
  };
  var today = function () { var t = new Date(); return iso(t.getFullYear(), t.getMonth(), t.getDate()); };
  var br = function (s) { var p = parts(s); return p ? pad(p.d) + '/' + pad(p.m + 1) + '/' + p.y : ''; };
  var longDate = function (s) { var p = parts(s); return p ? p.d + ' de ' + MONTHS[p.m] + ' de ' + p.y : ''; };
  var parseBr = function (text) {
    var t = (text || '').trim();
    if (!t) { return ''; }
    var m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/.exec(t) || /^(\d{2})(\d{2})(\d{4})$/.exec(t);
    if (!m) { return null; }
    var y = +m[3];
    if (y < 100) { y += 2000; }
    var mo = +m[2] - 1;
    var d = +m[1];
    return valid(y, mo, d) ? iso(y, mo, d) : null;
  };

  var el = function (tag, cls, html) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (html !== undefined) { node.innerHTML = html; }
    return node;
  };

  var dateOf = function (field) { return (proto.get.call(field.input) || '').slice(0, 10); };
  var timeOf = function (field) { var v = proto.get.call(field.input) || ''; return v.length > 10 ? v.slice(11, 16) : ''; };

  /* Grava no input original e avisa quem escuta (input e change). */
  var write = function (field, date) {
    var value = date;
    if (date && field.timed) {
      var time = (field.time && field.time.value) || timeOf(field) || (field.role === 'end' ? '18:00' : '08:00');
      value = date + 'T' + time;
    }
    if ((proto.get.call(field.input) || '') === value) { paint(field); return; }
    field.writing = true;
    proto.set.call(field.input, value);
    field.writing = false;
    paint(field);
    field.input.dispatchEvent(new Event('input', { bubbles: true }));
    field.input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  var paint = function (field) {
    var date = dateOf(field);
    field.text.value = br(date);
    field.text.removeAttribute('aria-invalid');
    field.error.hidden = true;
    if (field.time) { field.time.value = timeOf(field); }
    field.root.classList.toggle('has-value', !!date);
    field.clear.hidden = !date || field.input.required || field.input.disabled || field.input.readOnly;
  };

  var outOfBounds = function (field, date) {
    var min = (field.input.getAttribute('min') || '').slice(0, 10);
    var max = (field.input.getAttribute('max') || '').slice(0, 10);
    return (min && date < min) || (max && date > max);
  };

  var showError = function (field, text) {
    field.text.setAttribute('aria-invalid', 'true');
    field.error.textContent = text;
    field.error.hidden = false;
  };

  var commitText = function (field) {
    var parsed = parseBr(field.text.value);
    if (parsed === null) { showError(field, 'Data inválida. Use dd/mm/aaaa.'); return false; }
    if (parsed && outOfBounds(field, parsed)) {
      var min = field.input.getAttribute('min');
      var max = field.input.getAttribute('max');
      showError(field, min && parsed < min.slice(0, 10) ? 'A data não pode ser antes de ' + br(min) + '.' : 'A data não pode ser depois de ' + br(max) + '.');
      return false;
    }
    write(field, parsed);
    return true;
  };

  /* ----- Calendário (um por campo ou par) ----- */

  var Picker = function (start, end) {
    this.start = start;
    this.end = end || null;
    this.range = !!end;
    this.node = null;
    this.from = null;
    this.to = null;
    this.hover = null;
    this.focus = null;
    this.view = null;
    this.picking = 'start';
    this.opener = null;
  };

  Picker.prototype.months = function () {
    return this.range && window.matchMedia && !window.matchMedia('(max-width: 640px)').matches ? 2 : 1;
  };

  Picker.prototype.open = function (from, opener) {
    if (openPicker && openPicker !== this) { openPicker.close(false); }
    openPicker = this;
    this.opener = opener;
    this.from = dateOf(this.start) || null;
    this.to = this.end ? (dateOf(this.end) || null) : null;
    this.picking = this.range && from === this.end ? (this.from ? 'end' : 'start') : 'start';
    var anchor = (this.picking === 'end' ? this.to : this.from) || this.from || this.to || today();
    var min = (this.start.input.getAttribute('min') || '').slice(0, 10);
    var max = ((this.end || this.start).input.getAttribute('max') || '').slice(0, 10);
    if (min && anchor < min) { anchor = min; }
    if (max && anchor > max) { anchor = max; }
    this.focus = anchor;
    var a = parts(anchor);
    this.view = iso(a.y, a.m, 1);
    if (this.months() === 2 && this.picking === 'end' && this.from && this.to && this.from.slice(0, 7) !== this.to.slice(0, 7)) {
      this.view = addMonths(this.view, -1);
    }
    this.build();
    this.position(from);
    this.focusDay();
  };

  Picker.prototype.close = function (restore) {
    if (!this.node) { return; }
    this.node.remove();
    this.node = null;
    [this.start, this.end].forEach(function (f) { if (f) { f.button.setAttribute('aria-expanded', 'false'); } });
    if (openPicker === this) { openPicker = null; }
    if (restore !== false && this.opener) { this.opener.focus(); }
  };

  Picker.prototype.title = function () {
    var label = function (f) {
      var l = f.input.id && document.querySelector('label[for="' + f.text.id + '"]');
      return l ? l.textContent.replace(/\s+/g, ' ').trim() : '';
    };
    if (this.range) { return 'Escolha o período' + (label(this.start) ? ' (' + label(this.start) + ' e ' + label(this.end) + ')' : ''); }
    return 'Escolha a data' + (label(this.start) ? ': ' + label(this.start) : '');
  };

  Picker.prototype.build = function () {
    var self = this;
    var node = el('div', 'cx-datepop' + (this.range ? ' cx-datepop--range' : ''));
    node.setAttribute('role', 'dialog');
    node.setAttribute('aria-modal', 'false');
    node.setAttribute('aria-label', this.title());
    node.id = 'cx-datepop-' + (++uid);
    node.addEventListener('keydown', function (e) { self.key(e); });
    node.addEventListener('mousedown', function (e) { if (e.target.closest('.cx-datepop__day')) { e.preventDefault(); } });
    this.node = node;
    if (this.range) {
      var hint = el('p', 'cx-datepop__hint');
      hint.setAttribute('aria-live', 'polite');
      node.appendChild(hint);
    }
    var months = el('div', 'cx-datepop__months');
    node.appendChild(months);
    var foot = el('div', 'cx-datepop__foot');
    var shortcut = el('div', 'cx-datepop__shortcuts');
    var mk = function (text, fn, cls) { var b = el('button', 'cx-datepop__link' + (cls ? ' ' + cls : '')); b.type = 'button'; b.textContent = text; b.addEventListener('click', fn); return b; };
    if (this.range && !this.start.timed) {
      shortcut.appendChild(mk('Este mês', function () { var t = parts(today()); self.apply(iso(t.y, t.m, 1), iso(t.y, t.m, new Date(t.y, t.m + 1, 0).getDate())); }));
      shortcut.appendChild(mk('Mês passado', function () { var t = parts(today()); var a = new Date(t.y, t.m - 1, 1); self.apply(iso(a.getFullYear(), a.getMonth(), 1), iso(a.getFullYear(), a.getMonth(), new Date(a.getFullYear(), a.getMonth() + 1, 0).getDate())); }));
      shortcut.appendChild(mk('Últimos 30 dias', function () { self.apply(addDays(today(), -29), today()); }));
    } else if (!this.range) {
      shortcut.appendChild(mk('Hoje', function () { var t = today(); if (!outOfBounds(self.start, t)) { self.apply(t, null); } }));
    }
    var actions = el('div', 'cx-datepop__actions');
    if (!this.start.input.required || this.range) { actions.appendChild(mk('Limpar', function () { self.apply('', ''); }, 'cx-datepop__link--muted')); }
    actions.appendChild(mk('Fechar', function () { self.close(); }, 'cx-datepop__link--muted'));
    foot.appendChild(shortcut);
    foot.appendChild(actions);
    node.appendChild(foot);
    /* Dentro de uma janela modal (<dialog>) o calendário vai junto, senão fica por trás dela. */
    (this.start.root.closest('dialog[open]') || document.body).appendChild(node);
    [this.start, this.end].forEach(function (f) { if (f) { f.button.setAttribute('aria-expanded', 'true'); f.button.setAttribute('aria-controls', node.id); } });
    this.draw();
  };

  Picker.prototype.inRange = function (d) {
    var a = this.from;
    var b = this.to || (this.picking === 'end' && this.hover && this.from ? this.hover : null);
    if (!a || !b) { return false; }
    if (b < a) { var t = a; a = b; b = t; }
    return d > a && d < b;
  };

  Picker.prototype.draw = function () {
    var self = this;
    var wrap = this.node.querySelector('.cx-datepop__months');
    wrap.innerHTML = '';
    var count = this.months();
    var min = (this.start.input.getAttribute('min') || '').slice(0, 10);
    var max = ((this.end || this.start).input.getAttribute('max') || '').slice(0, 10);
    var now = today();
    for (var i = 0; i < count; i++) {
      var first = addMonths(this.view, i);
      var p = parts(first);
      var month = el('div', 'cx-datepop__month');
      var head = el('div', 'cx-datepop__head');
      var prev = el('button', 'cx-datepop__nav', ICON_PREV);
      prev.type = 'button';
      prev.setAttribute('aria-label', 'Mês anterior');
      prev.addEventListener('click', function () { self.shift(-1); });
      var next = el('button', 'cx-datepop__nav', ICON_NEXT);
      next.type = 'button';
      next.setAttribute('aria-label', 'Próximo mês');
      next.addEventListener('click', function () { self.shift(1); });
      var caption = el('h2', 'cx-datepop__title');
      caption.id = this.node.id + '-m' + i;
      caption.textContent = MONTHS[p.m].charAt(0).toUpperCase() + MONTHS[p.m].slice(1) + ' ' + p.y;
      caption.setAttribute('aria-live', i === 0 ? 'polite' : 'off');
      prev.style.visibility = i === 0 ? '' : 'hidden';
      next.style.visibility = i === count - 1 ? '' : 'hidden';
      if (i !== 0) { prev.tabIndex = -1; }
      if (i !== count - 1) { next.tabIndex = -1; }
      head.appendChild(prev);
      head.appendChild(caption);
      head.appendChild(next);
      month.appendChild(head);
      var grid = el('table', 'cx-datepop__grid');
      grid.setAttribute('role', 'grid');
      grid.setAttribute('aria-labelledby', caption.id);
      var thead = el('thead');
      var hr = el('tr');
      DAYS.forEach(function (d, k) { var th = el('th'); th.scope = 'col'; th.textContent = d; th.setAttribute('abbr', DAYS_LONG[k]); hr.appendChild(th); });
      thead.appendChild(hr);
      grid.appendChild(thead);
      var body = el('tbody');
      var startDow = new Date(p.y, p.m, 1).getDay();
      var cursor = addDays(first, -startDow);
      for (var w = 0; w < 6; w++) {
        var tr = el('tr');
        for (var k = 0; k < 7; k++) {
          var td = el('td');
          var c = parts(cursor);
          var outside = c.m !== p.m;
          if (outside) {
            td.className = 'cx-datepop__out';
            td.setAttribute('aria-hidden', 'true');
            var ghost = el('span', 'cx-datepop__ghost');
            ghost.textContent = String(c.d);
            if (this.range && this.inRange(cursor)) { td.className += ' is-between'; }
            td.appendChild(ghost);
          } else {
            td.setAttribute('role', 'gridcell');
            var btn = el('button', 'cx-datepop__day');
            btn.type = 'button';
            btn.textContent = String(c.d);
            btn.setAttribute('data-date', cursor);
            btn.setAttribute('aria-label', DAYS_LONG[new Date(c.y, c.m, c.d).getDay()] + ', ' + longDate(cursor));
            btn.tabIndex = cursor === this.focus ? 0 : -1;
            var off = (min && cursor < min) || (max && cursor > max);
            if (off) { btn.setAttribute('aria-disabled', 'true'); btn.classList.add('is-disabled'); }
            var isStart = cursor === this.from;
            var isEnd = this.range && cursor === this.to;
            if (isStart || isEnd) { btn.classList.add('is-selected'); td.setAttribute('aria-selected', 'true'); } else { td.setAttribute('aria-selected', 'false'); }
            if (this.range && this.from && (this.to || this.hover) && this.from !== (this.to || this.hover)) {
              var lo = this.from < (this.to || this.hover) ? this.from : (this.to || this.hover);
              var hi = this.from < (this.to || this.hover) ? (this.to || this.hover) : this.from;
              if (cursor === lo) { td.classList.add('is-range-start'); }
              if (cursor === hi) { td.classList.add('is-range-end'); }
            }
            if (this.range && this.inRange(cursor)) { td.classList.add('is-between'); }
            if (cursor === now) { btn.classList.add('is-today'); btn.setAttribute('aria-current', 'date'); }
            if (k === 0) { td.classList.add('is-week-start'); }
            if (k === 6) { td.classList.add('is-week-end'); }
            (function (date, disabled) {
              btn.addEventListener('click', function () { if (!disabled) { self.pick(date); } });
              btn.addEventListener('mouseenter', function () { if (self.range && self.picking === 'end' && self.from && !self.to) { self.hover = date; self.paintRange(); } });
            })(cursor, off);
            td.appendChild(btn);
          }
          tr.appendChild(td);
          cursor = addDays(cursor, 1);
        }
        body.appendChild(tr);
      }
      grid.appendChild(body);
      month.appendChild(grid);
      var chosen = el('p', 'cx-datepop__chosen');
      if (this.range) {
        chosen.textContent = i === 0 ? (this.from ? 'Início: ' + longDate(this.from) : 'Início: escolha o dia') : (this.to ? 'Fim: ' + longDate(this.to) : 'Fim: escolha o dia');
        if (count === 1) { chosen.textContent = (this.from ? longDate(this.from) : '—') + ' até ' + (this.to ? longDate(this.to) : '—'); }
      } else {
        chosen.textContent = this.from ? longDate(this.from) : 'Nenhuma data escolhida';
      }
      month.appendChild(chosen);
      wrap.appendChild(month);
    }
    var hint = this.node.querySelector('.cx-datepop__hint');
    if (hint) { hint.textContent = this.picking === 'end' ? 'Agora escolha o dia final.' : 'Escolha o dia inicial.'; }
  };

  /* Só repinta as classes do intervalo (passar o mouse não pode recriar os botões). */
  Picker.prototype.paintRange = function () {
    var self = this;
    var end = this.to || this.hover;
    var lo = this.from && end ? (this.from < end ? this.from : end) : null;
    var hi = this.from && end ? (this.from < end ? end : this.from) : null;
    Array.prototype.forEach.call(this.node.querySelectorAll('.cx-datepop__day'), function (b) {
      var d = b.getAttribute('data-date');
      var td = b.parentNode;
      td.classList.toggle('is-between', self.inRange(d));
      td.classList.toggle('is-range-start', !!lo && lo !== hi && d === lo);
      td.classList.toggle('is-range-end', !!hi && lo !== hi && d === hi);
      b.classList.toggle('is-preview', !self.to && !!self.hover && d === self.hover && d !== self.from);
    });
  };

  Picker.prototype.shift = function (n) {
    this.view = addMonths(this.view, n);
    var p = parts(this.focus);
    var v = parts(this.view);
    var last = new Date(v.y, v.m + 1, 0).getDate();
    this.focus = iso(v.y, v.m, Math.min(p.d, last));
    this.draw();
  };

  Picker.prototype.focusDay = function () {
    var btn = this.node && this.node.querySelector('.cx-datepop__day[data-date="' + this.focus + '"]');
    if (btn) { btn.focus(); }
  };

  Picker.prototype.move = function (date) {
    var min = (this.start.input.getAttribute('min') || '').slice(0, 10);
    var max = ((this.end || this.start).input.getAttribute('max') || '').slice(0, 10);
    if (min && date < min) { date = min; }
    if (max && date > max) { date = max; }
    this.focus = date;
    var last = addMonths(this.view, this.months() - 1);
    if (date < this.view) { var p = parts(date); this.view = iso(p.y, p.m, 1); }
    else if (date.slice(0, 7) > last.slice(0, 7)) { var q = parts(date); this.view = addMonths(iso(q.y, q.m, 1), -(this.months() - 1)); }
    if (this.range && this.picking === 'end' && this.from && !this.to) { this.hover = date; }
    this.draw();
    this.focusDay();
  };

  Picker.prototype.key = function (e) {
    var day = e.target.closest && e.target.closest('.cx-datepop__day');
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(); return; }
    if (e.key === 'Tab') {
      var items = Array.prototype.filter.call(this.node.querySelectorAll('button'), function (b) { return b.tabIndex >= 0 && b.offsetParent !== null && b.style.visibility !== 'hidden'; });
      if (!items.length) { return; }
      var idx = items.indexOf(document.activeElement);
      if (e.shiftKey && idx <= 0) { e.preventDefault(); items[items.length - 1].focus(); }
      else if (!e.shiftKey && idx === items.length - 1) { e.preventDefault(); items[0].focus(); }
      return;
    }
    if (!day) { return; }
    var d = day.getAttribute('data-date');
    var map = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    if (map[e.key]) { e.preventDefault(); this.move(addDays(d, map[e.key])); return; }
    if (e.key === 'PageUp') { e.preventDefault(); this.move(addMonths(d, e.shiftKey ? -12 : -1)); return; }
    if (e.key === 'PageDown') { e.preventDefault(); this.move(addMonths(d, e.shiftKey ? 12 : 1)); return; }
    var dow = parts(d) && new Date(parts(d).y, parts(d).m, parts(d).d).getDay();
    if (e.key === 'Home') { e.preventDefault(); this.move(addDays(d, -dow)); return; }
    if (e.key === 'End') { e.preventDefault(); this.move(addDays(d, 6 - dow)); return; }
  };

  Picker.prototype.pick = function (date) {
    this.focus = date;
    if (!this.range) { this.apply(date, null); return; }
    if (this.picking === 'start' || !this.from) {
      this.from = date;
      this.to = null;
      this.hover = null;
      this.picking = 'end';
      this.draw();
      this.focusDay();
      return;
    }
    var a = this.from;
    var b = date;
    if (b < a) { var t = a; a = b; b = t; }
    this.apply(a, b);
  };

  Picker.prototype.apply = function (from, to) {
    var start = this.start;
    var end = this.end;
    this.close();
    write(start, from || '');
    if (end) { write(end, to || ''); }
    var target = end && to ? end : start;
    (target.time || target.text).focus();
  };

  Picker.prototype.position = function (field) {
    var node = this.node;
    var anchor = (field || this.start).root.getBoundingClientRect();
    if (this.range && this.end) {
      var a = this.start.root.getBoundingClientRect();
      var b = this.end.root.getBoundingClientRect();
      if (Math.abs(a.top - b.top) < 4) { anchor = { left: Math.min(a.left, b.left), right: Math.max(a.right, b.right), top: a.top, bottom: Math.max(a.bottom, b.bottom) }; }
    }
    var vw = document.documentElement.clientWidth;
    var vh = window.innerHeight;
    var w = node.offsetWidth;
    var h = node.offsetHeight;
    var left = Math.max(8, Math.min(anchor.left, vw - w - 8));
    var top = anchor.bottom + 6;
    if (top + h > vh - 8 && anchor.top - h - 6 > 8) { top = anchor.top - h - 6; }
    node.style.left = left + 'px';
    node.style.top = top + 'px';
  };

  /* ----- Campo visível ----- */

  var enhance = function (input) {
    if (!input || fields.has(input) || (input.type !== 'date' && input.type !== 'datetime-local')) { return null; }
    if (input.getAttribute('data-cx-date') === 'off' || input.closest('[data-cx-date="off"]')) { return null; }
    var timed = input.type === 'datetime-local';
    var id = input.id || ('cx-date-' + (++uid));
    input.id = id;
    var root = el('span', 'cx-date' + (timed ? ' cx-date--time' : ''));
    var box = el('span', 'cx-date__box');
    var text = el('input', 'cx-date__text ' + (input.className || '').replace(/\bcx-date__native\b/, '').trim());
    text.type = 'text';
    text.id = id + '-cx';
    text.inputMode = 'numeric';
    text.autocomplete = 'off';
    text.placeholder = 'dd/mm/aaaa';
    text.maxLength = 10;
    text.disabled = input.disabled;
    text.readOnly = input.readOnly;
    if (input.required) { text.setAttribute('aria-required', 'true'); }
    var button = el('button', 'cx-date__button', ICON_CAL);
    button.type = 'button';
    button.disabled = input.disabled || input.readOnly;
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    var clear = el('button', 'cx-date__clear', '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>');
    clear.type = 'button';
    clear.setAttribute('aria-label', 'Limpar data');
    clear.hidden = true;
    var error = el('span', 'cx-date__error');
    error.id = id + '-cx-error';
    error.hidden = true;
    error.setAttribute('role', 'alert');
    text.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + error.id).trim());

    input.parentNode.insertBefore(root, input);
    box.appendChild(text);
    box.appendChild(clear);
    box.appendChild(button);
    root.appendChild(box);
    var time = null;
    if (timed) {
      time = el('input', 'cx-date__time ' + (input.className || '').trim());
      time.type = 'time';
      time.id = id + '-cx-time';
      time.disabled = input.disabled;
      time.readOnly = input.readOnly;
      root.appendChild(time);
    }
    root.appendChild(input);
    root.appendChild(error);
    input.classList.add('cx-date__native');
    input.setAttribute('tabindex', '-1');
    input.setAttribute('aria-hidden', 'true');

    /* O rótulo passa a apontar para o campo visível. */
    var labelFor = document.querySelector('label[for="' + id + '"]');
    var wrapLabel = input.closest('label');
    var labelText = '';
    if (labelFor) { labelFor.setAttribute('for', text.id); labelText = labelFor.textContent; }
    else if (wrapLabel) {
      labelText = Array.prototype.filter.call(wrapLabel.childNodes, function (n) { return n.nodeType === 3; }).map(function (n) { return n.textContent; }).join(' ');
      wrapLabel.setAttribute('for', text.id);
    }
    labelText = (labelText || input.getAttribute('aria-label') || input.getAttribute('placeholder') || input.name || 'Data').replace(/\s+/g, ' ').replace(/\*/g, '').trim();
    if (!labelFor && !wrapLabel) { text.setAttribute('aria-label', labelText); }
    if (input.getAttribute('aria-label')) { text.setAttribute('aria-label', input.getAttribute('aria-label')); }
    button.setAttribute('aria-label', 'Abrir calendário: ' + labelText);
    if (time) { time.setAttribute('aria-label', 'Horário: ' + labelText); }

    var field = { input: input, root: root, text: text, button: button, clear: clear, error: error, time: time, timed: timed, writing: false, picker: null, role: 'single' };
    fields.set(input, field);

    try {
      Object.defineProperty(input, 'value', {
        configurable: true,
        get: function () { return proto.get.call(input); },
        set: function (v) { proto.set.call(input, v); if (!field.writing) { paint(field); } }
      });
    } catch (e) { /* segue sem o atalho */ }

    text.addEventListener('input', function () {
      /* Máscara simples: põe as barras sozinho. */
      var digits = text.value.replace(/\D/g, '').slice(0, 8);
      var caret = text.selectionStart === text.value.length;
      if (/^\d+$/.test(text.value.replace(/\//g, '')) && caret) {
        var out = digits.slice(0, 2);
        if (digits.length > 2) { out += '/' + digits.slice(2, 4); }
        if (digits.length > 4) { out += '/' + digits.slice(4); }
        text.value = out;
      }
      error.hidden = true;
      text.removeAttribute('aria-invalid');
    });
    text.addEventListener('change', function () { commitText(field); });
    text.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { if (!commitText(field)) { e.preventDefault(); } }
      if (e.key === 'ArrowDown' && (e.altKey || !text.value)) { e.preventDefault(); field.picker.open(field, text); }
    });
    button.addEventListener('click', function () {
      if (field.picker.node) { field.picker.close(); return; }
      commitText(field);
      field.picker.open(field, button);
    });
    clear.addEventListener('click', function () { write(field, ''); text.focus(); });
    if (time) {
      time.addEventListener('change', function () { if (dateOf(field)) { write(field, dateOf(field)); } });
    }
    var form = input.form;
    if (form) { form.addEventListener('reset', function () { window.setTimeout(function () { paint(field); }, 0); }); }

    field.picker = new Picker(field, null);
    paint(field);
    return field;
  };

  /* ----- Pares de período ----- */

  var pairUp = function (scope) {
    var inputs = Array.prototype.filter.call(scope.querySelectorAll('input'), function (i) { var f = fields.get(i); return f && f.role === 'single'; });
    var byForm = function (a, b) { return a.form === b.form && (a.form || a.closest('fieldset, [data-cx-range-scope], .erp-filters, .studio-date-range, form') === b.closest('fieldset, [data-cx-range-scope], .erp-filters, .studio-date-range, form')); };
    var join = function (a, b) {
      var fa = fields.get(a);
      var fb = fields.get(b);
      if (!fa || !fb || fa.role !== 'single' || fb.role !== 'single' || fa.timed !== fb.timed) { return; }
      var picker = new Picker(fa, fb);
      fa.picker = picker;
      fb.picker = picker;
      fa.role = 'start';
      fb.role = 'end';
      fa.root.classList.add('cx-date--range-start');
      fb.root.classList.add('cx-date--range-end');
      /* O fim não pode ficar antes do início. */
      a.addEventListener('change', function () { if (dateOf(fb) && dateOf(fa) && dateOf(fb) < dateOf(fa)) { write(fb, dateOf(fa)); } });
      b.addEventListener('change', function () { if (dateOf(fa) && dateOf(fb) && dateOf(fb) < dateOf(fa)) { write(fa, dateOf(fb)); } });
    };
    var marked = inputs.filter(function (i) { return i.getAttribute('data-cx-range') === 'inicio'; });
    marked.forEach(function (a) {
      var group = a.getAttribute('data-cx-range-group');
      var b = inputs.filter(function (i) {
        return i.getAttribute('data-cx-range') === 'fim' && (group ? i.getAttribute('data-cx-range-group') === group : byForm(a, i));
      })[0];
      if (b) { join(a, b); }
    });
    PAIRS.forEach(function (pair) {
      inputs.filter(function (i) { return (i.name || '').toLowerCase() === pair[0]; }).forEach(function (a) {
        var b = inputs.filter(function (i) { return (i.name || '').toLowerCase() === pair[1] && byForm(a, i); })[0];
        if (b) { join(a, b); }
      });
    });
    /* Barra de filtros do ERP sem name: ids terminados em -from/-to. */
    inputs.filter(function (i) { return /-(from|de|inicio)$/.test(i.id || ''); }).forEach(function (a) {
      var base = a.id.replace(/-(from|de|inicio)$/, '');
      var b = inputs.filter(function (i) { return i.id === base + '-to' || i.id === base + '-ate' || i.id === base + '-fim'; })[0];
      if (b) { join(a, b); }
    });
  };

  var api = {
    enhance: function (input) { var f = enhance(input); if (f) { pairUp(input.form || document); } return !!f; },
    refresh: function (root) {
      var scope = root || document;
      Array.prototype.forEach.call(scope.querySelectorAll('input[type="date"], input[type="datetime-local"]'), enhance);
      pairUp(document);
    },
    close: function () { if (openPicker) { openPicker.close(); } },
    parse: parseBr,
    format: br
  };

  document.addEventListener('mousedown', function (e) {
    if (!openPicker || !openPicker.node) { return; }
    if (openPicker.node.contains(e.target)) { return; }
    var inside = [openPicker.start, openPicker.end].some(function (f) { return f && f.root.contains(e.target); });
    if (!inside) { openPicker.close(false); }
  });
  window.addEventListener('resize', function () { if (openPicker) { openPicker.close(false); } });
  /* O calendário fica fixo na tela: acompanha o campo quando a página ou a janela rolam. */
  window.addEventListener('scroll', function (e) {
    if (!openPicker || !openPicker.node || openPicker.node.contains(e.target)) { return; }
    openPicker.position(openPicker.opener && openPicker.end && openPicker.end.root.contains(openPicker.opener) ? openPicker.end : openPicker.start);
  }, true);
  document.addEventListener('focusin', function (e) {
    if (!openPicker || !openPicker.node) { return; }
    var inside = openPicker.node.contains(e.target) || [openPicker.start, openPicker.end].some(function (f) { return f && f.root.contains(e.target); });
    if (!inside) { openPicker.close(false); }
  });

  window.ConnectDatepicker = api;

  var start = function () {
    api.refresh(document);
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        var found = false;
        records.forEach(function (r) {
          Array.prototype.forEach.call(r.addedNodes, function (n) {
            if (n.nodeType !== 1 || (n.closest && n.closest('.cx-datepop'))) { return; }
            if ((n.matches && n.matches('input[type="date"], input[type="datetime-local"]')) || (n.querySelector && n.querySelector('input[type="date"], input[type="datetime-local"]'))) { found = true; }
          });
        });
        if (found) { api.refresh(document); }
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
