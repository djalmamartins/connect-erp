/* Central de Ajuda (ADR 0141): chat (abre o widget do Help Desk), mensagem por fetch, avaliação e índice ativo. */
(() => {
  'use strict';

  // "Conversar no chat" abre o mesmo widget do Help Desk (abrir chamado ou acompanhar protocolo).
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-hc-chat]');
    if (!trigger) return;
    event.preventDefault();
    const launcher = document.querySelector('.moves-helpdesk-launcher');
    if (launcher) {
      const panel = document.querySelector('.moves-helpdesk-panel');
      if (!panel || panel.hidden) launcher.click();
    } else {
      window.location.href = trigger.dataset.fallback || (document.querySelector('a[href$="#mensagem"]')?.href ?? '#mensagem');
    }
  });

  // Máscara simples de telefone: (00) 00000-0000.
  document.querySelectorAll('[data-hc-phone]').forEach((input) => {
    input.addEventListener('input', () => {
      const d = input.value.replace(/\D+/g, '').slice(0, 11);
      input.value = d.length > 10 ? `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
        : d.length > 6 ? `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
        : d.length > 2 ? `(${d.slice(0, 2)}) ${d.slice(2)}` : d;
    });
  });

  // Deixe a sua mensagem: vira chamado no Help Desk e mostra o protocolo.
  const form = document.querySelector('[data-hc-message]');
  if (form) {
    const box = document.querySelector('[data-hc-feedback]');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
      if (!form.checkValidity()) {
        const invalid = form.querySelector(':invalid');
        invalid?.setAttribute('aria-invalid', 'true');
        invalid?.focus();
        box.className = 'hc-feedback';
        box.textContent = 'Preencha nome, e-mail, assunto e a mensagem (pelo menos 10 caracteres).';
        return;
      }
      const button = form.querySelector('button[type=submit]');
      button.disabled = true;
      try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await response.json();
        box.className = 'hc-feedback' + (data.success ? ' is-success' : '');
        box.textContent = data.message || (data.success ? 'Mensagem enviada.' : 'Não foi possível enviar.');
        if (data.success) form.reset();
        else if (data.field && form.elements[data.field]) {
          form.elements[data.field].setAttribute('aria-invalid', 'true');
          form.elements[data.field].focus();
        }
      } catch {
        box.className = 'hc-feedback';
        box.textContent = 'Sem conexão agora. Tente de novo em instantes.';
      } finally {
        button.disabled = false;
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    });
  }

  // Foi útil? Sim/Não.
  const vote = document.querySelector('.hc-vote form');
  vote?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(vote);
    data.set('vote', event.submitter?.value || 'yes');
    const out = vote.parentElement.querySelector('p');
    try {
      const response = await fetch(vote.action, { method: 'POST', body: data });
      const result = await response.json();
      out.textContent = result.message || '';
      if (result.success) vote.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    } catch {
      out.textContent = 'Não foi possível registrar agora.';
    }
  });

  // Índice "Navegue pelo conteúdo": destaca a seção em leitura.
  const links = [...document.querySelectorAll('.hc-toc a[href^="#"]')];
  if (links.length && 'IntersectionObserver' in window) {
    const byId = new Map(links.map((a) => [decodeURIComponent(a.hash.slice(1)), a]));
    const observer = new IntersectionObserver((entries) => {
      entries.filter((e) => e.isIntersecting).forEach((e) => {
        links.forEach((a) => a.classList.remove('is-current'));
        byId.get(e.target.id)?.classList.add('is-current');
      });
    }, { rootMargin: '-90px 0px -70% 0px' });
    byId.forEach((_, id) => { const el = document.getElementById(id); if (el) observer.observe(el); });
  }
})();
