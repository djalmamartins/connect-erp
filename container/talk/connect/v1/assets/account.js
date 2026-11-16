// Connect Talk — "Trocar senha" (menu lateral, todas as telas)
const dlg = document.getElementById('pw-dialog');
const form = document.getElementById('pw-form');
const err = document.getElementById('pw-error');
const csrf = document.querySelector('meta[name=csrf]').content;
document.getElementById('my-password')?.addEventListener('click', () => { form.reset(); err.hidden = true; dlg.showModal(); });
form.querySelector('button[value=cancel]').addEventListener('click', (ev) => { ev.preventDefault(); dlg.close(); });
form.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const f = form.elements;
  const fail = (m) => { err.textContent = m; err.hidden = false; };
  if (f.namedItem('new').value !== f.namedItem('again').value) return fail('As duas senhas novas não conferem.');
  const res = await fetch('/api/me/password', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
    body: JSON.stringify({ current: f.namedItem('current').value, new: f.namedItem('new').value }) });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) return fail(data.error || 'Não foi possível trocar a senha.');
  dlg.close();
  const t = document.createElement('div'); t.className = 'toast'; t.textContent = 'Senha trocada.'; document.body.append(t); setTimeout(() => t.remove(), 3000);
});
