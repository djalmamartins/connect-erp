// Connect Talk — topo de todas as telas: busca (Ctrl K), notificações e menus suspensos.
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ICON = {
  queue: '<path d="M4 5h16v10H8l-4 4z"/>',
  unread: '<path d="M4 6h16v12H4zM4 7l8 6 8-6"/>',
  schedules: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v3M12 18.5v.5"/>',
  channels: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
};

// um menu aberto por vez; clique fora ou Esc fecha
const drops = [...document.querySelectorAll('.tb-drop, .envswitch')];
drops.forEach((d) => d.addEventListener('toggle', () => { if (d.open) drops.forEach((o) => { if (o !== d) o.open = false; }); }));
document.addEventListener('click', (e) => drops.forEach((d) => { if (d.open && !d.contains(e.target)) d.open = false; }));
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') drops.forEach((d) => { d.open = false; });
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#search').focus(); $('#search').select(); }
});
if (/Mac|iPhone|iPad/.test(navigator.platform)) $('.tb-search kbd').textContent = '⌘ K';
document.getElementById('my-password')?.addEventListener('click', () => drops.forEach((d) => { d.open = false; }));

// ------------------------------------------------------------ som de alerta
// Um toque curto e suave (gerado pelo navegador, sem arquivo). Liga/desliga no menu do usuário;
// a escolha fica neste navegador. O navegador só deixa tocar depois do primeiro clique na página.
const SOUND_KEY = 'talk.sound';
let audio = null;
let lastAlert = 0;
const soundOn = () => { try { return localStorage.getItem(SOUND_KEY) !== 'off'; } catch { return true; } };
function unlockAudio() {
  if (!audio) { try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch { return; } }
  if (audio.state === 'suspended') audio.resume().catch(() => {});
}
['pointerdown', 'keydown'].forEach((ev) => document.addEventListener(ev, unlockAudio, { capture: true, passive: true }));
function chime() {
  if (!audio || audio.state !== 'running') return;
  const t = audio.currentTime;
  [[880, 0], [1318.5, 0.13]].forEach(([f, d]) => { // duas notas (lá e mi), como um "plim"
    const o = audio.createOscillator(); const g = audio.createGain();
    o.type = 'sine'; o.frequency.value = f;
    g.gain.setValueAtTime(0.0001, t + d);
    g.gain.exponentialRampToValueAtTime(0.22, t + d + 0.02);
    g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.55);
    o.connect(g).connect(audio.destination); o.start(t + d); o.stop(t + d + 0.6);
  });
}
/** Avisa que chegou mensagem: toca o som (se ligado) e balança o sino. No máximo uma vez a cada 3 s. */
window.talkAlert = function talkAlert() {
  const now = Date.now();
  if (now - lastAlert < 3000) return;
  lastAlert = now;
  if (soundOn()) chime();
  const bell = $('#tb-notif summary');
  bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring');
};
function renderSound() {
  const on = soundOn();
  $('#sound-toggle').setAttribute('aria-pressed', String(on));
  $('#sound-state').textContent = on ? 'Ligado: toca quando chega mensagem' : 'Desligado';
}
$('#sound-toggle').addEventListener('click', () => {
  try { localStorage.setItem(SOUND_KEY, soundOn() ? 'off' : 'on'); } catch { /* sem armazenamento: fica ligado */ }
  renderSound(); unlockAudio();
  if (soundOn()) setTimeout(chime, 50); // toca uma vez para a pessoa ouvir como fica
});
renderSound();

let failures = 0;
let lastAttention = null; // fila + clientes que responderam, para tocar quando aumenta (telas sem tempo real)
async function loadNotifications() {
  try {
    const res = await fetch('/api/notifications', { headers: { Accept: 'application/json' } });
    if (res.status === 401) { location.href = '/login'; return; }
    const d = await res.json();
    failures = 0;
    const n = $('#tb-notif-n');
    n.hidden = !d.count; n.textContent = d.count > 99 ? '99+' : d.count;
    $('#tb-notif summary').classList.toggle('has', !!d.count);
    const attention = d.items.filter((it) => it.kind === 'queue' || it.kind === 'unread').reduce((a, it) => a + it.count, 0);
    if (lastAttention !== null && attention > lastAttention && Date.now() - lastAlert > 25000) window.talkAlert();
    lastAttention = attention;
    $('#tb-notif summary').setAttribute('aria-label', d.count ? `Notificações (${d.count})` : 'Notificações');
    $('#tb-notif-list').innerHTML = d.items.map((it) => `<a class="tb-item" href="${esc(it.href)}">
        <span class="tb-ico k-${esc(it.kind)}"><svg viewBox="0 0 24 24">${ICON[it.kind] || ''}</svg></span>
        <span><b>${esc(it.title)}</b><small>${esc(it.text)}</small></span></a>`).join('')
      || '<p class="tb-empty">Tudo em dia. Nada pede sua atenção agora.</p>';
    // telas sem tempo real próprio: mostra que o Talk está respondendo
    const live = $('#live');
    if (live && !live.classList.contains('on') && !live.dataset.sse) { live.textContent = '● Online'; live.classList.add('on'); }
  } catch {
    failures += 1;
    if (failures > 1) { const live = $('#live'); if (live) { live.textContent = '● sem conexão'; live.classList.remove('on'); } }
  }
}
loadNotifications();
setInterval(loadNotifications, 20000);
$('#tb-notif').addEventListener('toggle', (e) => { if (e.target.open) loadNotifications(); });
