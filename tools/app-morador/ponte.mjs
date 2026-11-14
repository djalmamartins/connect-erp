// Ponte do celular para o Connect que roda no Docker do Mac (ADR 0303).
// O container só escuta em 127.0.0.1, então o celular no mesmo Wi-Fi não alcança. Esta ponte
// escuta na rede local e repassa SÓ as rotas do app do morador (/api/v1/resident/*) e o
// /api/v1/health. ERP, Studio, site e o resto da API continuam fora do alcance da rede.
// Uso: node tools/app-morador/ponte.mjs [porta-da-ponte] [porta-do-connect]
import http from 'node:http';

const listenPort = Number(process.argv[2] || 8090);
const targetPort = Number(process.argv[3] || process.env.CONNECT_HTTP_PORT || 8088);
const allowed = (path) => path === '/api/v1/health' || path.startsWith('/api/v1/resident/');

const server = http.createServer((req, res) => {
  const parsed = new URL(req.url || '/', 'http://ponte');
  const path = parsed.pathname;
  if (!allowed(path) || path.includes('..')) {
    res.writeHead(404, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({ error: { code: 'not_found', message: 'A ponte do celular só repassa o app do morador.' } }));
    return;
  }
  const upstream = http.request(
    { host: '127.0.0.1', port: targetPort, method: req.method, path: path + parsed.search, headers: { ...req.headers, host: 'localhost' } },
    (up) => {
      res.writeHead(up.statusCode || 502, up.headers);
      up.pipe(res);
    },
  );
  upstream.on('error', () => {
    if (!res.headersSent) {
      res.writeHead(502, { 'Content-Type': 'application/json; charset=utf-8' });
    }
    res.end(JSON.stringify({ error: { code: 'bad_gateway', message: 'O Connect não respondeu no Docker do Mac.' } }));
  });
  req.pipe(upstream);
  const when = new Date().toLocaleTimeString('pt-BR');
  console.log(`${when} ${req.method} ${path}`);
});

server.listen(listenPort, '0.0.0.0', () => {
  console.log(`Ponte do celular ouvindo na porta ${listenPort}, repassando para 127.0.0.1:${targetPort}`);
});
