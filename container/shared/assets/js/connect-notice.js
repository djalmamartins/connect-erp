/*
 * Connect — avisos no canto superior direito (ADR 0120), para o Studio, o site e as telas de login.
 * Sem dependências. Converte as mensagens do servidor (Message::render, marcadas com data-notice) em cartões:
 * sucesso, alerta, erro e informação, com ícone redondo, título, mensagem e X. No sucesso, a marca da Connect
 * se apaga, vira uma bolinha e desenha o check, uma vez só. O ERP tem a mesma interface no próprio erp.js,
 * porque o bundle do ERP não carrega arquivos de fora do tema.
 */
(function () {
  'use strict';
  if (window.ConnectNotice) return;

  var CSS = '' +
    '.cn-notices{position:fixed;top:20px;right:20px;z-index:100000;display:grid;gap:10px;width:min(400px,calc(100vw - 32px));pointer-events:none;font-family:inherit}' +
    '.cn-notice{--tone:#18587d;--soft:#eaf3fc;--badge:#18587d;display:flex;align-items:center;gap:14px;padding:12px 10px 12px 12px;border:1px solid rgba(0,0,0,.08);border-radius:11px;background:var(--soft);color:var(--tone);box-shadow:0 14px 34px rgba(20,44,33,.14);pointer-events:auto;text-align:left;animation:cn-in .28s cubic-bezier(.2,.7,.2,1) both}' +
    '.cn-notice--success{--tone:#12805c;--soft:#e3f4ec;--badge:#12805c}' +
    '.cn-notice--warning{--tone:#9a6400;--soft:#fff8e8;--badge:#e0a019}' +
    '.cn-notice--error{--tone:#d92d36;--soft:#fdecee;--badge:#d92d36}' +
    '.cn-notice__icon{flex:0 0 40px;display:grid;place-items:center;width:40px;height:40px;border-radius:50%;background:var(--badge);color:#fff}' +
    '.cn-notice__body{flex:1;min-width:0}' +
    '.cn-notice__body strong{display:block;font-size:14.5px;font-weight:700;line-height:1.3}' +
    '.cn-notice__body p{margin:2px 0 0;font-size:13.5px;line-height:1.4;overflow-wrap:anywhere}' +
    '.cn-notice__close{flex:0 0 32px;display:grid;place-items:center;width:32px;height:32px;margin:0;padding:0;border:0;border-radius:50%;background:none;color:inherit;opacity:.6;cursor:pointer}' +
    '.cn-notice__close:hover,.cn-notice__close:focus-visible{opacity:1;background:rgba(0,0,0,.06)}' +
    '.cn-notice__close svg{width:18px;height:18px}' +
    '.cn-notice--leaving{animation:cn-out .26s cubic-bezier(.2,.7,.2,1) both}' +
    '@keyframes cn-in{from{opacity:0;transform:translateX(28px)}to{opacity:1;transform:none}}' +
    '@keyframes cn-out{to{opacity:0;transform:translateX(28px)}}' +
    '@media (max-width:600px){.cn-notices{top:12px;right:16px}}' +
    '@media (prefers-reduced-motion:reduce){.cn-notice,.cn-notice--leaving{animation-duration:.01ms}}' +
    '@media print{.cn-notices{display:none}}';

  var ICONS = {
    success: '<svg viewBox="0 0 120 120" width="30" height="30" aria-hidden="true" focusable="false"><mask id="erp-done-mask" maskUnits="userSpaceOnUse" x="0" y="0" width="120" height="120"><path fill="none" stroke="#fff" stroke-width="17" stroke-linecap="butt" stroke-linejoin="miter" stroke-miterlimit="4" stroke-dasharray="210.0 210.0" stroke-dashoffset="420.0" d="M55.5 9.0L55.9 26.6 52.0 27.4 48.2 28.7 44.7 30.6 41.4 32.8 38.5 35.5 35.9 38.6 33.8 41.9 32.2 45.6 31.0 49.4 30.4 53.3 30.3 57.3 30.8 61.2 31.7 65.1 33.2 68.8 35.1 72.3 37.5 75.4 40.3 78.3 43.1 81.1 45.9 83.9 48.7 86.7 51.6 89.6 54.4 92.4 57.2 95.2 60.0 98.0 62.9 95.1 65.7 92.3 68.6 89.4 71.4 86.6 74.3 83.7 77.1 80.9 80.0 78.0 82.8 75.1 85.2 71.8 87.1 68.2 88.5 64.5 89.4 60.5 89.7 56.5 89.5 52.5 88.7 48.5 87.5 44.7 85.7 41.0 83.4 37.7 80.7 34.7 77.6 32.1 74.2 29.9 70.5 28.2 66.7 27.1 62.7 26.4"><animate attributeName="stroke-dashoffset" begin="0.2s" dur="0.7s" fill="freeze" from="420.0" to="222.0" calcMode="spline" keyTimes="0;1" keySplines=".45 0 .55 1"/></path></mask><g transform="translate(60 60)"><g><animateTransform attributeName="transform" type="scale" begin="1.53s" dur=".4s" fill="freeze" values="1;1.1;1" keyTimes="0;.45;1" calcMode="spline" keySplines=".3 0 .4 1;.5 0 .4 1"/><g transform="translate(-60 -60)"><g mask="url(#erp-done-mask)"><path fill="currentColor" transform="translate(60 56) scale(.72) translate(-52.9 -61.7)" d="M42.6,10.8C18.9,15.6,1.1,36.6,1.1,61.7c0,14,5.5,26.7,14.5,36l37.5,37.6l37.5-37.6c9-9.3,14.5-22,14.5-36c0-27.5-21.4-50.1-48.5-51.9v20.4c0,0,0,0,0.1,0h-0.1v10.5l6.1-9.2c12.5,4,21.5,15.8,21.5,29.6c0,8.4-3.3,16-8.7,21.6l0,0l-22.5,22.5L30.6,82.7l0,0C25.3,77.1,22,69.5,22,61.1c0-16,12.1-29.2,27.6-30.9V9.8c0,0,0,0,0,0V0.3L42.6,10.8L42.6,10.8z"/><set attributeName="visibility" to="hidden" begin="0.9s" fill="freeze"/></g><circle r="7.5" fill="currentColor" visibility="hidden"><set attributeName="visibility" to="visible" begin="0.9s" fill="freeze"/><animateMotion begin="0.9s" dur="0.32s" fill="freeze" path="M68.6 27.6Q49.3 13.6 30 64" calcMode="spline" keyPoints="0;1" keyTimes="0;1" keySplines=".5 0 .9 .55"/><set attributeName="visibility" to="hidden" begin="1.27s" fill="freeze"/></circle><path fill="none" stroke="currentColor" stroke-width="15" stroke-linecap="round" stroke-linejoin="round" visibility="hidden" stroke-dasharray="91.9 91.9" stroke-dashoffset="91.4" d="M30.0 64.0L30.9 64.9 31.8 65.8 32.8 66.8 33.7 67.7 34.6 68.6 35.5 69.5 36.4 70.4 37.3 71.3 38.2 72.2 39.2 73.2 40.1 74.1 41.0 75.0 41.9 75.9 42.8 76.8 43.8 77.8 44.7 78.7 45.6 79.6 46.5 80.5 47.4 81.4 48.3 82.3 49.2 83.2 50.2 84.2 51.1 85.1 52.0 86.0 53.8 84.2 55.5 82.3 57.2 80.5 59.0 78.7 60.8 76.8 62.5 75.0 64.2 73.2 66.0 71.3 67.8 69.5 69.5 67.7 71.2 65.8 73.0 64.0 74.8 62.2 76.5 60.3 78.2 58.5 80.0 56.7 81.8 54.8 83.5 53.0 85.2 51.2 87.0 49.3 88.8 47.5 90.5 45.7 92.2 43.8 94.0 42.0"><set attributeName="visibility" to="visible" begin="1.22s" fill="freeze"/><animate attributeName="stroke-dashoffset" begin="1.22s" dur="0.36s" fill="freeze" from="91.4" to="0" calcMode="spline" keyTimes="0;1" keySplines=".3 0 .2 1"/></path></g></g></g></svg>',
    warning: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M12 7.5v6" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/><circle cx="12" cy="17.4" r="1.6" fill="currentColor"/></svg>',
    error: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M7.5 7.5l9 9M16.5 7.5l-9 9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>',
    info: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="6.8" r="1.6" fill="currentColor"/><path d="M12 10.8v7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>'
  };
  var STATIC_CHECK = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6.5 12.5l3.6 3.5 7.4-7.6" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var TITLES = { success: 'Sucesso!', warning: 'Atenção!', error: 'Erro!', info: 'Informação' };
  var SECONDS = { success: 4.5, info: 6, warning: 8, error: 9 };
  var DONE_SECONDS = 2;
  var seq = 0;

  function kindOf(tone) {
    if (tone === 'danger' || tone === 'error') return 'error';
    if (tone === 'success' || tone === 'warning') return tone;
    return 'info';
  }

  function region() {
    var box = document.querySelector('[data-cn-notices]');
    if (box) return box;
    if (!document.getElementById('cn-notice-style')) {
      var style = document.createElement('style');
      style.id = 'cn-notice-style';
      style.textContent = CSS;
      document.head.appendChild(style);
    }
    box = document.createElement('div');
    box.className = 'cn-notices';
    box.setAttribute('data-cn-notices', '');
    box.setAttribute('aria-live', 'polite');
    document.body.appendChild(box);
    return box;
  }

  function show(message, tone, options) {
    if (!message || !document.body) return;
    var kind = kindOf(tone);
    var animated = kind === 'success' && !(options && options.quiet);
    var box = region();
    var item = document.createElement('div');
    item.className = 'cn-notice cn-notice--' + kind;
    item.setAttribute('role', kind === 'error' || kind === 'warning' ? 'alert' : 'status');
    var icon = document.createElement('span');
    icon.className = 'cn-notice__icon';
    // Cada aviso de sucesso ganha a própria máscara: dois ao mesmo tempo não dividem a animação.
    icon.innerHTML = kind === 'success' && !animated ? STATIC_CHECK : ICONS[kind].replace(/erp-done-mask/g, 'cn-done-mask-' + (++seq));
    var body = document.createElement('div');
    body.className = 'cn-notice__body';
    var title = document.createElement('strong');
    title.textContent = (options && options.title) || TITLES[kind];
    var text = document.createElement('p');
    text.textContent = message;
    body.appendChild(title);
    body.appendChild(text);
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'cn-notice__close';
    close.setAttribute('aria-label', 'Fechar aviso');
    close.innerHTML = ICONS.error;
    item.appendChild(icon);
    item.appendChild(body);
    item.appendChild(close);

    var closed = false;
    var timer = null;
    var dismiss = function () {
      if (closed) return;
      closed = true;
      window.clearTimeout(timer);
      item.classList.add('cn-notice--leaving');
      window.setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 260);
    };
    var wait = function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(dismiss, ((options && options.seconds) || SECONDS[kind]) * 1000);
    };
    close.addEventListener('click', dismiss);
    item.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
    item.addEventListener('mouseleave', wait);
    item._cnDismiss = dismiss;
    var open = box.querySelectorAll('.cn-notice:not(.cn-notice--leaving)');
    if (open.length >= 3 && open[0]._cnDismiss) open[0]._cnDismiss();
    box.appendChild(item);

    var svg = icon.querySelector('svg');
    if (animated && svg && svg.pauseAnimations && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      svg.pauseAnimations();
      svg.setCurrentTime(DONE_SECONDS);
    }
    wait();
  }

  // Mensagem do servidor (flash ou resposta AJAX): <div class='message tipo' data-notice>texto</div>.
  function convert(node) {
    if (!node || node.nodeType !== 1 || !node.matches('.message[data-notice]')) return;
    if (node.getAttribute('data-notice') === 'shown') return;
    node.setAttribute('data-notice', 'shown');
    var kind = ['success', 'warning', 'error', 'info'].filter(function (name) { return node.classList.contains(name); })[0] || 'info';
    var text = node.textContent.replace(/\s+/g, ' ').trim();
    var parent = node.parentNode;
    if (parent) parent.removeChild(node);
    show(text, kind);
  }

  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('.message[data-notice]')) { convert(root); return; }
    Array.prototype.forEach.call(root.querySelectorAll('.message[data-notice]'), convert);
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    var open = document.querySelectorAll('[data-cn-notices] .cn-notice:not(.cn-notice--leaving)');
    if (open.length && open[open.length - 1]._cnDismiss) open[open.length - 1]._cnDismiss();
  });

  function start() {
    scan(document.body);
    // Mensagens que chegam depois, por AJAX (jQuery .html(), .append(), .prepend()).
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (record) {
          Array.prototype.forEach.call(record.addedNodes, scan);
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  window.ConnectNotice = { show: show };
})();
