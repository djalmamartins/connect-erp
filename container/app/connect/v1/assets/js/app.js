/* Connect Morador (ADR 0302): copiar linha digitável/Pix, confirmar cancelamentos, trocar unidade,
   fechar o menu "Mais" e registrar o service worker (instalação e tela sem conexão). */
(function () {
  'use strict';
  function toast(text) {
    var el = document.createElement('div');
    el.className = 'app-toast';
    el.setAttribute('role', 'status');
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 2600);
  }
  document.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-app-copy]');
    if (copy) {
      var value = copy.getAttribute('data-app-copy');
      var done = function () { toast('Copiado. Cole no app do seu banco.'); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done, function () { window.prompt('Copie o código:', value); });
      } else {
        window.prompt('Copie o código:', value);
      }
      return;
    }
    var more = document.querySelector('.app-tabs__more[open]');
    if (more && !more.contains(event.target)) more.removeAttribute('open');
  });
  document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute('data-app-confirm');
    if (message && !window.confirm(message)) event.preventDefault();
  });
  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-app-autosubmit]')) event.target.form.submit();
  });
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferred = event;
    document.querySelectorAll('[data-app-install]').forEach(function (b) { b.hidden = false; });
  });
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-app-install]');
    if (button && deferred) { deferred.prompt(); deferred = null; button.hidden = true; }
  });
  var sw = document.body.getAttribute('data-sw');
  if (sw && 'serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register(sw, { scope: document.body.getAttribute('data-scope') }).catch(function () {});
  }
})();
