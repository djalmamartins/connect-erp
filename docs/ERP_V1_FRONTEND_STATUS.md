# Connect ERP v1 — status do frontend

Atualizado em 2026-11-07 · branch `claude-layout` · fase atual: **todas as páginas com interface visual**, aguardando revisão (identidade aprovada por Djalma em 2026-11-07).

## URL local

- **`https://erp.connect.lab`** — host próprio do ERP (rotas na raiz: `/login`, `/dashboard`, `/condominios`…). Neste host só existem o ERP, o 2FA do login (`/studio/login/2fa`, `/studio/seguranca/2fa`) e a recuperação de senha (`/forget`); site, Studio e Help Desk não respondem. Links antigos em `/erp/...` continuam funcionando no mesmo host.
- `https://www.connect.lab/erp` — o mesmo ERP no host do site, mantido para transição.
- Infra do lab: vhost em `/Volumes/MAC HD/Docker/xampp/apache/connect.conf`, `erp.connect.lab` em `scripts/update-lab-hosts.sh`, certificado `certs/erp-connect.pem` (mkcert). O compose `connect-erp` serve o checkout principal (`www/connect`, `main`).
- Prévia estática: https://claude.ai/artifact/DaVWJZRTTejtFhi7mK5GZH

## Números

| Item | Quantidade |
|---|---:|
| Rotas no inventário da auditoria | 172 |
| Removidas (fornecedor SaaS, ADR-002) | 3 (AP-169, AP-170, AP-172) |
| Fundidas por duplicidade | 3 (AP-055→052, AP-077→051, AP-078→050) |
| Páginas validadas e com rota registrada | 166 |
| Visões gerais de módulo (hubs) | 10 |
| Rotas de acesso | 6 (`/erp`, `/erp/login` GET/POST, `/erp/sair` POST, `/erp/recuperar-senha`, `/erp/seguranca/codigos`) |
| Páginas com interface do seu tipo | 166 (Dashboard + 165) e o login reaproveitado do Studio |
| Por tipo | 67 listagens · 54 relatórios · 14 configurações · 9 importações · 8 gráficos · 6 formulários · 4 documentos · 2 painéis · 1 fechamento |
| Páginas genéricas "em construção" | 0 |
| Rotas cobertas pelo smoke de renderização | 166 páginas + 10 hubs + estados + login |
| Rotas testadas por HTTP no connect.lab | 0 (bloqueado no ambiente; ver Pendências) |

Matriz completa (módulo, página, rota, componente, layout, ações, validação, implementação, teste): [`docs/ERP_V1_ROUTE_MATRIX.md`](ERP_V1_ROUTE_MATRIX.md), gerada por `tools/erp-catalog.py`.

## O que foi feito

- **Carregamento do tema**: `moves_container_path/url('erp', …)` resolvem `container/erp/{tema}/v1` (alias `default` → `connect`) e `index.php` inclui o `routes.php` do ERP. São as mesmas linhas do A1 da frente `claude-dev`, para o merge ser limpo.
- **Rotas**: `container/erp/connect/v1/routes.php` registra tudo pelo MovesCode Router (`Source\Core\Router`), a partir do catálogo `components/layout/catalog.php`.
- **Login**: a tela do Studio (`container/cms/studio/v1/components/login/login.php`) é reaproveitada com `loginEnvironment = erp`; mesmo `Source\Models\Auth`, CSRF, limite de tentativas (`erp_login`, 8 em 5 min), lembrar e-mail e mostrar/ocultar senha. O 2FA usa o fluxo existente em `/studio/login/2fa?next=erp`; `Studio\TwoFactor` passou a aceitar `next=erp`, exige `erp.access` e leva a `/erp/dashboard`. Ao ativar o 2FA obrigatório, os códigos de recuperação aparecem em `/erp/seguranca/codigos`. Sem credencial fixa e sem bypass.
- **Autorização**: toda rota do ERP exige sessão completa (`authUser` só existe depois do 2FA) e `erp.access`; sem permissão, 403 sem a navegação. Logout por POST com CSRF.
- **Visual**: igual ao Realizze PJ (sidebar verde profunda, canvas claro, Inter), com a logo do site da Connect na sidebar (decisão de 2026-11-08, que substitui a identidade do Studio com dourado).
- **Layout único** (`default.php`): sidebar verde profunda com 12 grupos em `<details>`, item ativo, recolher (lembrado no navegador) e gaveta no celular com `inert`, Escape e foco; navbar com título, breadcrumbs, pesquisa das páginas (Ctrl + K), notificações, configurações, menu do usuário e Sair.
- **Dashboard**: 4 indicadores, receitas × despesas (barras com tabela acessível), inadimplência por atraso (rosca), tabela de condomínios com filtro, ordenação e paginação, alertas, atividades, agenda e modal "Comunicado rápido" com validação; o envio avisa que está pendente.
- **Visão geral de cada módulo**: cards de todas as páginas do grupo, por seção, com filtro.
- **Dados de demonstração**: `database/fixtures/erp-demo/dashboard.php`, lidos por `Source\Services\Erp\DemoData`. Ligado por `ERP_DEMO_DATA=true|false`; sem a variável, ligado fora de produção e desligado em produção. Desligado, o dashboard mostra o estado vazio. Nada é gravado no banco.

## Componentes reutilizáveis

| Componente | Arquivo |
|---|---|
| Design system (tokens, botões, campos, tabelas, cards, badges, modais, toasts, skeletons, abas, paginação, estados, gráficos, responsividade, acessibilidade) | `assets/css/erp.css` · documentação em `docs/architecture/ERP_DESIGN_SYSTEM.md` |
| Comportamentos (sidebar, dropdowns, pesquisa, modais, abas, ordenação/paginação, validação, ações pendentes, toasts) | `assets/js/erp.js` |
| Ícones | `components/layout/icons.php` (helper `$icon()`) |
| Sidebar · Navbar · Breadcrumbs | `components/sidebar/sidebar.php` · `components/navbar/navbar.php` · `components/breadcrumbs/breadcrumbs.php` |
| Cabeçalho de página | `components/layout/page-head.php` |
| Indicador | `components/cards/kpi.php` |
| Gráficos | `components/charts/bars.php`, `components/charts/donut.php` |
| Estado vazio | `components/feedback/empty.php` |
| Modelos de página por tipo | `pages/modules/{list,report,form,import,settings,document,chart,closing,dashboard}.php` |
| Campo, tabela, filtros, modais, aviso de proposta | `components/forms/field.php`, `components/tables/data-table.php`, `components/filters/filter-bar.php`, `components/modals/form-modal.php`, `components/modals/confirm.php`, `components/notifications/proposal.php` |
| Definições e dados | `components/layout/page-specs.php`, `Source\Support\Erp\FieldSpec`, `Source\Services\Erp\DemoData::rows()`, `database/fixtures/erp-demo/vocabulary.php` |
| Páginas de sistema | `pages/system/hub.php`, `pages/system/state.php`, `pages/system/pending.php` (reserva), `pages/auth/security.php` (2FA da própria conta) |

## Testes executados

| Comando | Resultado |
|---|---|
| `php -l` em todos os PHP alterados/novos | sem erros |
| `node --check container/erp/connect/v1/assets/js/erp.js` | sem erros |
| `python3 tools/erp-catalog.py` | 166 páginas, 3 removidas, 3 fundidas, nenhuma rota duplicada |
| `python3 tools/erp-page-specs.py` | 165 definições de página |
| `VENDOR=/Volumes/MAC\ HD/Docker/xampp/www/connect/vendor php tests/erp/render-smoke.php` | **2.884 verificações, 0 falhas** (2026-11-08, com Condomínios reais) |

O smoke renderiza, sem banco, pelo mesmo `Source\Core\View` do controller: dashboard (indicadores, gráficos, tabela, modal, aviso de demonstração), os 10 hubs (todos os links), as 165 páginas restantes pelo mesmo `Panel::pageView()` da rota (template do tipo certo, nenhuma página genérica, ids únicos, elementos do tipo: tabela/abas/exclusão nas listagens, totais nos relatórios com valores, arquivo e etapas nas importações etc., além de sidebar, navbar, breadcrumbs, título, item ativo, um `<main>`, logout com CSRF e a ficha de origem), listagem sem linhas com a demonstração desligada, todos os links internos contra as rotas registradas, estados 403/404, códigos de recuperação, dashboard com demonstração desligada e o login do ERP e do Studio (o do Studio continua igual). Avisos PHP contam como falha.

**Não executados** (o sandbox bloqueia Docker, loopback e o banco): requisição HTTP real no connect.lab, login com usuário real e 2FA, console JavaScript no navegador, PHPUnit. A prévia publicada foi gerada do HTML renderizado, não navegada aqui.

## Como testar no connect.lab

```bash
cd "/Volumes/MAC HD/Docker/xampp/worktrees/connect-claude-layout" && ln -sfn "/Volumes/MAC HD/Docker/xampp/www/connect/vendor" vendor
```

Depois, rodar o compose a partir do worktree (ou fazer o merge em um ambiente de teste) e abrir `https://www.connect.lab/erp`. Smoke sem banco:

```bash
VENDOR="/Volumes/MAC HD/Docker/xampp/www/connect/vendor" php tests/erp/render-smoke.php --out=/tmp/erp-render
```

## Pendências

1. **Revisão das páginas** pela prévia ou no connect.lab.
2. **Confirmar colunas e campos**: as definições em `components/layout/page-specs.php` (gerado por `tools/erp-page-specs.py`) são propostas derivadas do nome e do domínio de cada página, avisadas na tela. As fichas `docs/specs/pages/ap-*.md` ainda estão com campos PENDENTE e precisam ser confirmadas antes do backend.
3. **Permissões por módulo**: só `erp.access` e `erp.condominiums.*` existem (migration `20261107_0003`). As outras páginas exigem `erp.access`; cada módulo precisa da sua permissão quando ganhar backend. O menu já esconde páginas sem a permissão própria (`Panel::PAGE_PERMISSIONS`).
4. **Recuperação de senha em www.connect.lab/erp**: no host do site, `/forget` só existe com o site aberto (`CONF_ACCESS_SITE`) ou para developer. Em `erp.connect.lab` a rota é registrada sempre.
5. **Gestão do 2FA**: resolvida em 2026-11-08 com `/seguranca` no próprio ERP (ativar, renovar códigos e desativar com o código atual; `Erp\Connect\Security`). A tela do Studio continua existindo para quem usa o Studio.
6. **Testes HTTP e de navegador** (rotas, autenticação, permissões, console JS, 390/768/1440 px) no connect.lab, e a suíte PHPUnit (`tests/legacy/Unit/ErpThemeTest.php` foi atualizado para a moldura nova, mas não rodou aqui: o PHPUnit precisa do vendor do próprio checkout).

Resolvidas em 2026-11-08: merge com `claude-dev` (moldura e CSS deste branch, helpers de `container/send`, Condomínios reais em `/erp/condominios` e `/erp/condominios/{id}` com `CondominiumScope`), bundle via MovesCode Compress (`Services/Minify/Erp.php` publica `assets/erp.min.css` e `assets/erp.min.js` só com o design system do ERP e só quando a fonte muda) e recuperação de senha real.

## Próximas etapas

1. Djalma revisa as páginas e indica ajustes.
2. Confirmar campos nas fichas e ligar o backend por módulo, começando por Condomínios (listagem, cadastro, detalhe, edição, vínculos), Unidades e pessoas, e Financeiro.
3. Testes HTTP automatizados de todas as rotas autenticadas no container e relatório de cobertura atualizado.
