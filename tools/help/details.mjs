// Central de Ajuda (ADR 0141): levantamento detalhado de cada página do menu para escrever os
// tutoriais com os nomes reais da tela: ações do cabeçalho e o que cada uma abre (modal ou
// formulário, com campos, obrigatórios, dicas e botão final), filtros, colunas, ações da linha,
// abas, cartões, avisos e exportações. Grava tools/help/.detalhes.json.
//
//   HELP_BASE_URL=http://localhost:8089/erp HELP_USER=... HELP_PASSWORD=... node tools/help/details.mjs [--only AP-001,AP-006]
//
// Só para o banco de demonstração (*_test): clica nos botões do cabeçalho para descobrir o modal,
// mas não envia formulário. Mesmo assim, rode o demo-seed de novo antes dos prints.
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const base = (process.env.HELP_BASE_URL || 'http://localhost:8089/erp').replace(/\/$/, '');
if (!/localhost|127\.0\.0\.1|\.test(:|\/|$)/.test(base) && !process.env.HELP_ALLOW_REMOTE) {
  throw new Error('Só em host local com o banco de demonstração (HELP_ALLOW_REMOTE=1 para liberar).');
}
const args = process.argv.slice(2);
const only = (args.find((a, i) => args[i - 1] === '--only') || '').split(',').filter(Boolean);
const condo = process.env.HELP_CONDOMINIO || 'residencial-jardim-das-acacias';
const catalog = JSON.parse(execFileSync('php', ['-r', 'echo json_encode((require "container/erp/connect/v1/components/layout/catalog.php")["pages"]);'], { cwd: root, encoding: 'utf8' }));

const browser = await chromium.launch({ args: ['--lang=pt-BR'] });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo' });
const page = await context.newPage();
page.on('dialog', (d) => d.dismiss().catch(() => {}));
await page.goto(`${base}/login`);
await page.fill('input[name=email]', process.env.HELP_USER);
await page.fill('input[name=password]', process.env.HELP_PASSWORD);
await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
await page.waitForTimeout(1000);

// Lê um formulário (modal ou página): campos com rótulo, obrigatório, tipo, opções e dica.
const FORM_READER = `(scope) => {
  const text = (el) => (el?.innerText || el?.textContent || '').replace(/\\s+/g, ' ').trim();
  const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const fields = [];
  for (const control of scope.querySelectorAll('input, select, textarea')) {
    if (['hidden', 'submit', 'button', 'reset'].includes(control.type) || control.name === 'csrf' || !visible(control)) continue;
    const field = control.closest('.erp-field, label, .erp-check, div');
    let label = control.id ? text(scope.querySelector('label[for="' + CSS.escape(control.id) + '"]')) : '';
    if (!label && control.closest('label')) label = text(control.closest('label')).slice(0, 80);
    if (!label) label = text(field?.querySelector('.erp-field__label, label')) || control.getAttribute('aria-label') || control.placeholder || control.name;
    const hint = text(field?.querySelector('.erp-field__hint, small'));
    const options = control.tagName === 'SELECT' ? [...control.options].map((o) => text(o)).filter((o) => o && !/^(Escolha|Selecione|Todos|Todas|—|-)/i.test(o)).slice(0, 8) : [];
    fields.push({ label: label.replace(/\\s*\\*$/, '').slice(0, 90), name: control.name || '', type: control.tagName === 'SELECT' ? 'select' : control.tagName === 'TEXTAREA' ? 'textarea' : control.type, required: control.required, hint: hint !== label ? hint.slice(0, 200) : '', options, placeholder: control.placeholder || '' });
  }
  const seen = new Set();
  const unique = fields.filter((f) => { const k = f.label + '|' + f.type; if (seen.has(k)) return false; seen.add(k); return true; });
  const submit = [...scope.querySelectorAll('button[type=submit], .erp-form__actions button, .erp-modal__foot button')].filter(visible).map(text).filter((t) => t && !/^(Voltar|Cancelar|Fechar)$/i.test(t));
  return {
    title: text(scope.querySelector('h2, h1, legend')),
    intro: [...scope.querySelectorAll('p')].filter(visible).map(text).filter((t) => t.length > 20 && t.length < 400).slice(0, 3),
    sections: [...scope.querySelectorAll('fieldset legend, .erp-form__section h3, h3')].filter(visible).map(text).slice(0, 10),
    fields: unique.slice(0, 40),
    submit: [...new Set(submit)].slice(0, 3),
  };
}`;

const PAGE_READER = () => {
  const text = (el) => (el?.innerText || el?.textContent || '').replace(/\s+/g, ' ').trim();
  const uniq = (list) => [...new Set(list.filter(Boolean))];
  const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const main = document.querySelector('main') || document.body;
  const head = main.querySelector('.erp-page-head');
  const firstRow = main.querySelector('table tbody tr');
  return {
    title: text(head?.querySelector('h1') || main.querySelector('h1')),
    eyebrow: text(head?.querySelector('.erp-eyebrow')),
    lead: text(head?.querySelector('p')),
    actions: [...(head?.querySelectorAll('.erp-page-head__actions a, .erp-page-head__actions button') || [])].filter(visible).map((el) => ({ text: text(el), tag: el.tagName.toLowerCase(), href: el.getAttribute('href') || '', modal: el.getAttribute('data-erp-modal-open') || '', submit: el.type === 'submit' })).filter((a) => a.text),
    tabs: uniq([...main.querySelectorAll('[role=tab], .erp-tabs a, .erp-tabs button')].filter(visible).map(text)).slice(0, 10),
    filters: uniq([...main.querySelectorAll('form[method=get] label, .erp-filters label, form[method=get] .erp-field__label')].filter(visible).map(text)).filter((t) => t && t.length < 50),
    filterButtons: uniq([...main.querySelectorAll('form[method=get] button, .erp-filters button')].filter(visible).map(text)),
    columns: uniq([...main.querySelectorAll('table thead th')].map(text)).filter((t) => t && t.length < 50),
    rowActions: firstRow ? uniq([...firstRow.querySelectorAll('a, button')].filter(visible).map((el) => text(el) || el.getAttribute('aria-label') || el.title)).filter((t) => t && t.length < 40) : [],
    rows: main.querySelectorAll('table tbody tr').length,
    cards: uniq([...main.querySelectorAll('.erp-card h2, .erp-card__head h2, .erp-kpi__label, .erp-stat__label, section > h2')].filter(visible).map(text)).filter((t) => t.length < 70).slice(0, 14),
    notes: uniq([...main.querySelectorAll('.erp-alert, .erp-note, .erp-callout, .erp-empty p, .erp-empty')].filter(visible).map(text)).filter((t) => t.length > 15 && t.length < 400).slice(0, 4),
    buttons: uniq([...main.querySelectorAll('button, a.erp-btn')].filter(visible).map(text)).filter((t) => t && t.length < 40).slice(0, 30),
    hasForm: !!main.querySelector('form:not([method=get]) input:not([type=hidden]), form:not([method=get]) select, form:not([method=get]) textarea'),
    dialogs: [...document.querySelectorAll('dialog')].map((d) => ({ id: d.id, title: text(d.querySelector('h2')) })),
    demo: !!main.querySelector('.erp-demo-flag, .erp-demo-banner, [data-erp-demo]') || /dados de (exemplo|demonstração)/i.test(text(main)),
  };
};

const out = {};
for (const [code, info] of Object.entries(catalog)) {
  if (only.length && !only.includes(code)) continue;
  const url = `${base}/${condo}${info.route}`;
  try {
    await page.goto(url, { waitUntil: 'networkidle' });
    const detail = await page.evaluate(PAGE_READER);
    detail.route = info.route;
    detail.kind = info.kind;
    detail.group = info.group;
    detail.name = info.name;
    if (detail.hasForm) detail.form = await page.evaluate(`(${FORM_READER})(document.querySelector('main form:not([method=get])'))`);
    // Cada ação do cabeçalho: modal (clicando, sem enviar) ou página de formulário (seguindo o link).
    for (const [index, action] of detail.actions.entries()) {
      if (action.submit) continue;
      if (action.tag === 'button' || action.modal || action.href.startsWith('#')) {
        try {
          await page.locator('main .erp-page-head__actions').locator('a, button').nth(index).click({ timeout: 3000 });
          await page.waitForTimeout(500);
          const opened = await page.$('dialog[open]');
          if (opened) {
            action.dialog = await page.evaluate(`(${FORM_READER})(document.querySelector('dialog[open]'))`);
            action.dialogId = await opened.getAttribute('id');
            await page.keyboard.press('Escape');
            await page.waitForTimeout(200);
          }
        } catch (error) {
          action.error = error.message.split('\n')[0];
        }
        if (!page.url().startsWith(url)) await page.goto(url, { waitUntil: 'networkidle' });
      } else if (action.href && /\/(novo|nova|importar|enviar|gerar)(\?|$|\/)/.test(action.href)) {
        await page.goto(new URL(action.href, url).href, { waitUntil: 'networkidle' });
        const form = await page.$('main form:not([method=get])');
        if (form) action.form = await page.evaluate(`(${FORM_READER})(document.querySelector('main form:not([method=get])'))`);
        action.formRoute = new URL(page.url()).pathname.replace(new RegExp(`^.*?/erp(/${condo})?`), '');
        await page.goto(url, { waitUntil: 'networkidle' });
      }
    }
    out[code] = detail;
    console.log(`${code} ${detail.title} · ${detail.actions.map((a) => a.text + (a.dialog ? '[modal]' : a.form ? '[form]' : '')).join(', ')}`);
  } catch (error) {
    out[code] = { route: info.route, error: error.message.split('\n')[0] };
    console.log(`${code} ERRO ${out[code].error}`);
  }
}
writeFileSync(join(root, 'tools/help/.detalhes.json'), JSON.stringify(out, null, 2));
await browser.close();
console.log(`Detalhes de ${Object.keys(out).length} páginas em tools/help/.detalhes.json`);
