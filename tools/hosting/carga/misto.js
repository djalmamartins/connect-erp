/**
 * Teste de carga misto (ADR 0600): moradores no app nativo (70%), no app web (25%), visitantes do site e da Central de
 * Ajuda (4,5%) e a equipe no ERP (0,5%), subindo em degraus (STEP pessoas a cada RAMP, segurando HOLD).
 * Só contra o lab ou uma máquina de teste com dados fictícios (tools/hosting/carga/README.md). Nunca contra produção.
 *
 *   k6 run --local-ips=127.0.1.1-127.0.40.255 -e SERVER=127.0.0.1:8099 -e MAX=10000 -e STEP=2000 tools/hosting/carga/misto.js
 *
 * --local-ips dá um IP de origem a cada pessoa, como na vida real, para o limite de tentativas por IP (ADR 0326) não
 * barrar o teste. Os moradores são morador1@carga.lab ... morador10000@carga.lab (criar-moradores.php).
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter } from 'k6/metrics';

const SERVER = __ENV.SERVER || '127.0.0.1:8099';
const HOSTS = { 'app.connect.lab': SERVER, 'www.connect.lab': SERVER, 'erp.connect.lab': SERVER };
const U = (h) => `http://${h}.connect.lab`;
const MAX = parseInt(__ENV.MAX || '10000');
const STEP = parseInt(__ENV.STEP || '2000');
const RAMP = __ENV.RAMP || '2m', HOLD = __ENV.HOLD || '3m';
const erros = new Counter('erros');
const reenvios = new Counter('reenvios_eof');
// Condomínios que a equipe abre no ERP (ids separados por vírgula; padrão: as cópias 1 a 20 do clonar-condominio.php).
const CONDOS = (__ENV.CONDOS || Array.from({ length: 20 }, (_, i) => (i + 1) * 1000000 + 3).join(',')).split(',').map(Number);
const ADMIN = __ENV.ADMIN_EMAIL || 'admin.carga@connect.lab';
const SENHA = __ENV.SENHA || 'Carga@2026';
const ERP_PAGES = ['/dashboard', '/condominios', '/condominios/unidades', '/cadastros/proprietarios-e-inquilinos', '/cadastros/fornecedores',
  '/cobranca/boletos', '/cobranca/acordos', '/cobranca/parcelas-de-acordo', '/cobranca/remessas', '/cobranca/retornos', '/cobranca/multas-pendentes',
  '/financeiro/lancar-receita', '/financeiro/outros-lancamentos', '/financeiro/recibos', '/operacional/chamados', '/operacional/leituras',
  '/comunicacao/comunicados-gerais', '/documentos/certidao-de-debitos', '/relatorios/inadimplentes-geral', '/relatorios/relatorio-de-lancamentos'];

const stages = []; const fases = [];
for (let n = STEP; n <= MAX; n += STEP) { stages.push({ duration: RAMP, target: n }, { duration: HOLD, target: n }); fases.push(n); }
stages.push({ duration: '30s', target: 0 });
const rampS = parseInt(RAMP) * (RAMP.endsWith('m') ? 60 : 1), holdS = parseInt(HOLD) * (HOLD.endsWith('m') ? 60 : 1);
const thresholds = { http_req_failed: ['rate<0.01'] };
for (const f of fases) { thresholds[`http_req_duration{fase:${f}}`] = ['p(95)<2000']; thresholds[`http_req_failed{fase:${f}}`] = ['rate<0.01']; }
for (const p of ['nativo', 'web', 'site', 'erp']) thresholds[`http_req_duration{parte:${p}}`] = ['p(95)<2000'];
export const options = { hosts: HOSTS, scenarios: { misto: { executor: 'ramping-vus', startVUs: 0, stages, gracefulRampDown: '10s' } }, thresholds, summaryTrendStats: ['med', 'p(90)', 'p(95)', 'max'] };

function fase() {
  const s = exec.instance.currentTestRunDuration / 1000; const i = Math.floor(s / (rampS + holdS));
  // só conta a fase durante o "segurar"; na subida marca "subida"
  return (s - i * (rampS + holdS)) >= rampS && i < fases.length ? String(fases[i]) : 'subida';
}
const think = () => sleep(5 + Math.random() * 10);
let st = null;
function go(parte, name, method, url, body, params) {
  const p = Object.assign({ timeout: '60s', redirects: 5 }, params || {});
  p.tags = { name, parte, fase: fase() };
  let r = method === 'POST' ? http.post(url, body, p) : http.get(url, p);
  // Como o navegador: se uma conexão reaproveitada fecha sem resposta (EOF), tenta de novo uma vez.
  if (r.status === 0 && /EOF|reset/.test(String(r.error))) { reenvios.add(1); r = method === 'POST' ? http.post(url, body, p) : http.get(url, p); }
  if (!check(r, { ok: (x) => x.status >= 200 && x.status < 400 })) erros.add(1, { name, status: String(r.status) });
  return r;
}

function perfil(vu) { const m = vu % 200; return m < 140 ? 'nativo' : m < 190 ? 'web' : m < 199 ? 'site' : 'erp'; }
function morador(vu) { return `morador${((vu - 1) % 10000) + 1}@carga.lab`; }

export default function () {
  const vu = exec.vu.idInTest, kind = perfil(vu);
  if (kind === 'nativo') {
    const B = U('app'); const H = { 'Content-Type': 'application/json' };
    if (!st) { const r = go('nativo', 'login', 'POST', `${B}/api/v1/resident/app-sessions`, JSON.stringify({ login: morador(vu), password: SENHA }), { headers: H });
      if (r.status !== 201) { sleep(10); return; } st = r.json('data.token'); sleep(1); }
    const A = { headers: Object.assign({ Authorization: 'Bearer ' + st }, H) };
    go('nativo', 'summary', 'GET', `${B}/api/v1/resident/summary`, null, A);
    go('nativo', 'notifications', 'GET', `${B}/api/v1/resident/notifications`, null, A); think();
    go('nativo', 'charges', 'GET', `${B}/api/v1/resident/charges`, null, A); think();
    const extra = ['announcements', 'tickets', 'reservations', 'accountability'][Math.floor(Math.random() * 4)];
    go('nativo', extra, 'GET', `${B}/api/v1/resident/${extra}`, null, A); think();
  } else if (kind === 'web') {
    const B = U('app'); const H = {};
    if (!st) { const lp = go('web', 'login-page', 'GET', `${B}/login`, null, H);
      const m = /name=['"]csrf['"] value=['"]([a-f0-9]+)/.exec(lp.body || '');
      const r = go('web', 'login', 'POST', `${B}/login`, { csrf: m ? m[1] : '', email: morador(vu), password: SENHA }, H);
      if (!/connect\.lab\/(boas-vindas|$)/.test(r.url || '')) erros.add(1, { name: 'login-web-recusado', status: String(r.status) });
      go('web', 'static', 'GET', `${B}/container/app/connect/v1/assets/css/app.css`, null, H);
      go('web', 'static', 'GET', `${B}/container/app/connect/v1/assets/js/app.js`, null, H);
      st = 'cookie'; sleep(1); }
    for (const p of ['/boletos', ['/comunicados', '/minha-unidade', '/reservas', '/ocorrencias', '/encomendas', '/prestacao-de-contas'][Math.floor(Math.random() * 6)]]) { go('web', 'pagina', 'GET', `${B}${p}`, null, H); think(); }
  } else if (kind === 'site') {
    const B = U('www'); const H = {};
    go('site', 'home', 'GET', `${B}/`, null, H);
    if (!st) { for (const a of ['/container/shared/assets/organic/organic.min.css', '/container/web/default/v1/assets/site.min.css', '/container/shared/assets/organic/organic.global.min.js', '/container/web/default/v1/assets/site.min.js']) go('site', 'static', 'GET', `${B}${a}`, null, H); st = 'x'; }
    think(); go('site', 'suporte', 'GET', `${B}/suporte`, null, H); think(); think();
  } else {
    const B = U('erp'); const H = {};
    if (!st) { const lp = go('erp', 'login-page', 'GET', `${B}/login`, null, H);
      const m = /name=['"]csrf['"] value=['"]([a-f0-9]+)/.exec(lp.body || ''); 
      go('erp', 'login', 'POST', `${B}/login`, { csrf: m ? m[1] : '', email: ADMIN, password: SENHA }, H);
      st = ''; }
    if (!st || Math.random() < 0.1) { const r = go('erp', 'condominio', 'GET', `${B}/condominios/${CONDOS[Math.floor(Math.random() * CONDOS.length)]}`, null, H);
      const m = /erp\.connect\.lab\/([a-z0-9-]+)\/condominios/.exec(r.url || ''); st = m ? '/' + m[1] : ''; }
    if (Math.random() < 0.05) go('erp', 'global', 'GET', `${B}/dashboard`, null, H);
    for (let i = 0; i < 3; i++) { go('erp', 'pagina', 'GET', `${B}${st}${ERP_PAGES[Math.floor(Math.random() * ERP_PAGES.length)]}`, null, H); sleep(3 + Math.random() * 7); }
  }
}
