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
    // Acima de tudo, inclusive de uma modal aberta (ADR 0320): popover na camada do topo, ou z-index 99999.
    if (typeof el.showPopover === 'function') { el.setAttribute('popover', 'manual'); try { el.showPopover(); } catch (e) { /* fica o z-index */ } }
    setTimeout(function () { el.remove(); }, 2600);
  }
  document.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-app-copy]');
    if (copy) {
      var value = copy.getAttribute('data-app-copy');
      var done = function () { toast('Copiado. Cole no app do seu banco.'); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done, function () { window.ConnectDialog.copy('o código', value); });
      } else {
        window.ConnectDialog.copy('o código', value);
      }
      return;
    }
    var more = document.querySelector('.app-tabs__more[open]');
    if (more && !more.contains(event.target)) more.removeAttribute('open');
  });
  // Cancelamentos: a pergunta abre a modal do sistema (ADR 0320); com o sim, o formulário segue.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.hasAttribute('data-app-confirm')) return;
    if (form._appConfirmed) { form._appConfirmed = false; return; }
    event.preventDefault();
    var submitter = event.submitter || form.querySelector('[type="submit"]');
    window.ConnectDialog.ask(form, 'data-app-confirm', submitter ? (submitter.textContent || submitter.value || '').trim() : '').then(function (yes) {
      if (!yes) return;
      form._appConfirmed = true;
      if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); else form.submit();
    });
  });
  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-app-autosubmit], [data-app-autosubmit-file]')) event.target.form.submit();
  });
  // Rolagem infinita dos boletos: busca a janela anterior quando o fim da lista aparece.
  function loadMore(more) {
    if (more.dataset.loading) return;
    more.dataset.loading = '1';
    fetch(more.getAttribute('data-app-more'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
      .then(function (html) {
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        more.replaceWith(tpl.content);
        watchMore();
      })
      .catch(function () { delete more.dataset.loading; more.textContent = 'Não foi possível carregar. Toque para tentar de novo.'; more.onclick = function () { loadMore(more); }; });
  }
  function watchMore() {
    var more = document.querySelector('[data-app-more]');
    if (!more) return;
    if (!('IntersectionObserver' in window)) { loadMore(more); return; }
    var io = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) { io.disconnect(); loadMore(more); }
    }, { rootMargin: '400px' });
    io.observe(more);
  }
  watchMore();
  // Olho do Início: esconde e mostra os valores; a escolha fica neste aparelho.
  var HIDE = 'app-hide-values';
  try { if (localStorage.getItem(HIDE) === '1') document.documentElement.classList.add(HIDE); } catch (e) {}
  document.addEventListener('click', function (event) {
    var eye = event.target.closest('[data-app-eye]');
    if (!eye) return;
    var on = document.documentElement.classList.toggle(HIDE);
    eye.setAttribute('aria-pressed', on ? 'true' : 'false');
    eye.setAttribute('aria-label', on ? 'Mostrar valores' : 'Esconder valores');
    try { localStorage.setItem(HIDE, on ? '1' : '0'); } catch (e) {}
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
