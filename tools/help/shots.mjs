#!/usr/bin/env node
/*
 * Prints marcados da Central de Ajuda (ADR 0141).
 *
 * Lê os blocos ```tela de cada artigo (via `php tools/help.php screens`), entra no ERP, abre cada
 * tela, executa as ações "antes:", desenha as marcações numeradas por cima dos elementos e salva
 * container/web/support/v1/assets/kb/<id>.jpg. Um print só é regravado quando a imagem muda, então
 * rodar de novo depois de mudar uma tela atualiza apenas o que mudou.
 *
 * Os prints vão para um site público: rode SEMPRE contra a base de demonstração
 * (tools/help/prints.sh monta uma com dados fictícios). Nunca aponte para o banco do lab ou de produção.
 *
 * Variáveis: HELP_BASE_URL (ex.: http://localhost:8089/erp), HELP_USER, HELP_PASSWORD,
 * HELP_TOTP_SECRET (só se a conta tiver 2FA). Opções: --only id1,id2 (prints ou artigos), --strict
 * (falha se alguma marcação não for encontrada).
 */
import { execFileSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const out = join(root, 'container/web/support/v1/assets/kb');
const args = process.argv.slice(2);
const only = (args.find((a, i) => args[i - 1] === '--only') || (args.find((a) => a.startsWith('--only=')) || '').slice(7) || '');
const strict = args.includes('--strict');
const base = (process.env.HELP_BASE_URL || 'http://localhost:8089/erp').replace(/\/$/, '');

if (!process.env.HELP_USER || !process.env.HELP_PASSWORD) {
  console.error('Defina HELP_USER e HELP_PASSWORD (o tools/help/prints.sh faz isso com a base de demonstração).');
  process.exit(1);
}
if (!/localhost|127\.0\.0\.1|\.test(:|\/|$)/.test(base) && !process.env.HELP_ALLOW_REMOTE) {
  console.error(`Recusado: ${base} não é a base de demonstração local. Os prints são públicos e não podem mostrar dados reais.`);
  process.exit(1);
}

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch {
  console.error('Playwright não encontrado. Rode: npm --prefix tools/help install && npx --prefix tools/help playwright install chromium');
  process.exit(1);
}

const phpArgs = ['tools/help.php', 'screens'];
if (only) phpArgs.push('--only', only);
// HELP_SCREENS_FILE: JSON já exportado por `php tools/help.php screens` (o prints.sh roda o Node num
// container do Playwright, sem PHP).
const screens = JSON.parse(process.env.HELP_SCREENS_FILE ? readFileSync(process.env.HELP_SCREENS_FILE, 'utf8') : execFileSync('php', phpArgs, { cwd: root, encoding: 'utf8' }))
  .filter((screen) => !only || !process.env.HELP_SCREENS_FILE || only.split(',').some((key) => key === screen.id || key === screen.article));
if (!screens.length && !args.includes('--inventario')) {
  console.log('Nenhum print para gerar.');
  process.exit(0);
}
mkdirSync(out, { recursive: true });

/** Código TOTP (RFC 6238, 30 s, 6 dígitos) para contas com 2FA. */
function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hmac = createHmac('sha1', key).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 15;
  return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

/** Seletor das marcações: "menu" é o item ativo do menu lateral; o resto vai direto ao Playwright. */
function locate(page, selector) {
  if (selector === 'menu') return page.locator('.erp-nav [aria-current="page"]').first();
  if (selector === 'condominio') return page.locator('.erp-context, [data-erp-context], .erp-topbar select').first();
  return page.locator(selector).first();
}

async function run(page, step) {
  const [verb, ...rest] = step.split(' ');
  const value = rest.join(' ');
  if (verb === 'clicar') await locate(page, value).click();
  else if (verb === 'esperar') await page.waitForTimeout(Math.min(Number(value) || 500, 5000));
  else if (verb === 'rolar') await locate(page, value).scrollIntoViewIfNeeded();
  else if (verb === 'preencher') {
    const [selector, text] = value.split('=>').map((s) => s.trim());
    await locate(page, selector).fill(text ?? '');
  } else if (verb === 'escolher') {
    const [selector, option] = value.split('=>').map((s) => s.trim());
    await locate(page, selector).selectOption({ label: option });
  } else throw new Error(`ação desconhecida "${verb}"`);
}

// --lang: campos de data e mês nativos em português (dd/mm/aaaa).
const browser = await chromium.launch({ args: ['--lang=pt-BR'] });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
const page = await context.newPage();
const session = page;

await page.goto(`${base}/login`);
await page.fill('input[name=email]', process.env.HELP_USER);
await page.fill('input[name=password]', process.env.HELP_PASSWORD);
await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
await page.waitForTimeout(1200);
if (/2fa/.test(page.url())) {
  if (!process.env.HELP_TOTP_SECRET) throw new Error('A conta pede 2FA: defina HELP_TOTP_SECRET.');
  await page.fill('input[name=code]', totp(process.env.HELP_TOTP_SECRET));
  await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
}
if (/login/.test(page.url())) throw new Error('Não entrou no ERP: confira HELP_USER e HELP_PASSWORD.');

// --inventario: lê cada página do menu e grava os rótulos reais (título, texto de apoio, botões,
// filtros, colunas e campos) em tools/help/.inventario.json. O `php tools/help.php scaffold
// --inventario` usa esse arquivo para escrever o rascunho do artigo com os nomes certos.
if (args.includes('--inventario')) {
  const catalog = JSON.parse(process.env.HELP_CATALOG_FILE ? readFileSync(process.env.HELP_CATALOG_FILE, 'utf8') : execFileSync('php', ['-r', 'echo json_encode((require "container/erp/connect/v1/components/layout/catalog.php")["pages"]);'], { cwd: root, encoding: 'utf8' }));
  const inventory = {};
  for (const [code, info] of Object.entries(catalog)) {
    if (only && !only.split(',').includes(code)) continue;
    try {
      await page.goto(base + '/' + (process.env.HELP_CONDOMINIO || 'residencial-jardim-das-acacias') + info.route, { waitUntil: 'networkidle' });
      inventory[code] = await page.evaluate(() => {
        const text = (el) => (el?.innerText || el?.textContent || '').replace(/\s+/g, ' ').trim();
        const uniq = (list) => [...new Set(list.filter(Boolean))];
        const main = document.querySelector('main') || document.body;
        const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
        return {
          title: text(main.querySelector('h1')),
          eyebrow: text(main.querySelector('.erp-page-header__eyebrow, .erp-eyebrow, h1 ~ p, header small')),
          lead: text(main.querySelector('.erp-page-header p, .erp-lead, h1 + p')),
          actions: uniq([...main.querySelectorAll('.erp-page-header a.erp-btn, .erp-page-header button, header .erp-btn, .erp-actions .erp-btn')].filter(visible).map(text)).slice(0, 8),
          buttons: uniq([...main.querySelectorAll('button, a.erp-btn')].filter(visible).map(text)).filter((t) => t.length < 40).slice(0, 20),
          filters: uniq([...main.querySelectorAll('form[method=get] label, form[method=get] .erp-field__label, .erp-filters label')].filter(visible).map(text)).filter((t) => t && t.length < 40),
          columns: uniq([...main.querySelectorAll('table thead th')].map(text)).filter((t) => t.length < 40),
          fields: uniq([...main.querySelectorAll('form:not([method=get]) label, form:not([method=get]) .erp-field__label')].filter(visible).map(text)).filter((t) => t && t.length < 60).slice(0, 30),
          cards: uniq([...main.querySelectorAll('.erp-card__head h2, .erp-card h2, .erp-kpi__label, h2')].filter(visible).map(text)).slice(0, 12),
          demo: !!main.querySelector('.erp-demo-flag, .erp-demo-banner, [data-erp-demo]') || /dados de exemplo/i.test(main.innerText),
          empty: !!main.querySelector('.erp-empty'),
        };
      });
      inventory[code].route = info.route;
      console.log(`${code} ${inventory[code].title}`);
    } catch (error) {
      inventory[code] = { route: info.route, error: error.message.split('\n')[0] };
    }
  }
  writeFileSync(join(root, 'tools/help/.inventario.json'), JSON.stringify(inventory, null, 2));
  await browser.close();
  console.log(`Inventário de ${Object.keys(inventory).length} páginas em tools/help/.inventario.json`);
  process.exit(0);
}

const missing = [];
let written = 0;
for (const screen of screens) {
  const file = join(out, `${screen.id}.jpg`);
  // "sessao: nenhuma" tira o print sem login (tela de entrada, recuperar senha, verificação pública).
  const anonymous = (screen.options?.sessao || '') === 'nenhuma';
  const anonContext = anonymous ? await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' }) : null;
  const page = anonContext ? await anonContext.newPage() : session;
  try {
    // Condomínio da barra superior (ADR 0030): "condominio: <slug>" abre a tela nele; sem a opção,
    // /todos zera o contexto guardado na sessão, para um print não herdar o condomínio do anterior.
    const context = screen.options?.condominio ? `/${screen.options.condominio}` : (anonymous ? '' : '/todos');
    const url = screen.route.startsWith('/!') ? base.replace(/\/erp$/, '') + screen.route.slice(2) : base + context + screen.route;
    await page.goto(url, { waitUntil: 'networkidle' });
    // Rodapés internos (ficha da spec) não interessam ao usuário final.
    await page.addStyleTag({ content: '.erp-trace{display:none!important} *{caret-color:transparent!important} .erp-toast,.erp-flash{animation:none!important}' });
    for (const step of screen.before) await run(page, step);
    await page.waitForTimeout(300);

    // Posições em coordenadas do documento, medidas sem rolar a página.
    await page.evaluate(() => window.scrollTo(0, 0));
    const boxes = [];
    for (const mark of screen.marks) {
      const locator = locate(page, mark.selector);
      if (!(await locator.count()) || !(await locator.isVisible())) {
        missing.push(`${screen.id} #${mark.n}: ${mark.selector}`);
        continue;
      }
      const box = await locator.evaluate((el) => {
        const r = el.getBoundingClientRect();
        return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height };
      });
      if (box && box.width > 0) boxes.push({ n: mark.n, ...box });
    }
    await page.evaluate((boxes) => {
      const layer = document.createElement('div');
      layer.id = 'kb-marks';
      layer.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;pointer-events:none;z-index:2147483646';
      for (const b of boxes) {
        const pad = 6;
        const ring = document.createElement('div');
        ring.style.cssText = `position:absolute;left:${b.x - pad}px;top:${b.y - pad}px;width:${b.width + pad * 2}px;height:${b.height + pad * 2}px;border:3px solid #c5a131;border-radius:12px;box-shadow:0 0 0 4px rgba(197,161,49,.28),0 10px 28px rgba(13,46,39,.25)`;
        const badge = document.createElement('div');
        badge.textContent = String(b.n);
        badge.style.cssText = `position:absolute;left:${Math.max(4, b.x - pad - 15)}px;top:${Math.max(4, b.y - pad - 15)}px;width:30px;height:30px;border-radius:50%;background:#0d2e27;color:#f4d77a;border:2px solid #c5a131;font:800 15px/26px Inter,system-ui,sans-serif;text-align:center;box-shadow:0 4px 10px rgba(0,0,0,.25)`;
        layer.append(ring, badge);
      }
      // Modal aberto fica na camada superior e cobriria as marcas: nesse caso elas vão dentro dele,
      // com a posição relativa ao modal (que é position: fixed).
      const dialog = document.querySelector('dialog[open]');
      if (dialog) {
        const r = dialog.getBoundingClientRect();
        layer.style.left = `${-(r.left + window.scrollX + dialog.clientLeft) + dialog.scrollLeft}px`;
        layer.style.top = `${-(r.top + window.scrollY + dialog.clientTop) + dialog.scrollTop}px`;
        dialog.style.overflow = 'visible';
        dialog.append(layer);
      } else {
        document.body.append(layer);
      }
    }, boxes);

    const shotOptions = { type: 'jpeg', quality: 72 };
    let buffer;
    if (screen.frame === 'pagina') buffer = await page.screenshot({ ...shotOptions, fullPage: true });
    else if (screen.frame && screen.frame !== 'janela') {
      const target = locate(page, screen.frame);
      const box = await target.evaluate((el) => {
        const r = el.getBoundingClientRect();
        return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height };
      });
      const pad = 24;
      buffer = await page.screenshot({ ...shotOptions, fullPage: true, clip: { x: Math.max(0, box.x - pad), y: Math.max(0, box.y - pad), width: Math.min(1440, box.width + pad * 2), height: box.height + pad * 2 } });
    } else {
      // Janela; se alguma marcação ficou abaixo da dobra, o print desce até ela (máximo 1600 px).
      const bottom = Math.max(900, ...boxes.map((b) => Math.min(b.y + b.height, b.y + 260) + 40));
      buffer = await page.screenshot({ ...shotOptions, fullPage: bottom > 900, clip: { x: 0, y: 0, width: 1440, height: Math.min(1600, bottom) } });
    }

    if (!existsSync(file) || !readFileSync(file).equals(buffer)) {
      writeFileSync(file, buffer);
      written++;
      console.log(`print ${screen.id} (${screen.article})`);
    }
  } catch (error) {
    missing.push(`${screen.id}: ${error.message.split('\n')[0]}`);
  } finally {
    if (anonContext) await anonContext.close();
  }
}
await browser.close();

console.log(`${screens.length} prints conferidos, ${written} atualizados.`);
if (missing.length) {
  console.error(`${missing.length} marcação(ões) não encontrada(s) — a tela mudou? Ajuste o bloco \`\`\`tela do artigo:`);
  for (const item of missing) console.error(`  - ${item}`);
  if (strict) process.exit(1);
}
