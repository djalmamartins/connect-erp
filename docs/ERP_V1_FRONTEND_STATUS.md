# Connect ERP v1 — status do frontend

Atualizado em 2026-11-07 · branch `claude-layout` · fase atual: **aprovação da identidade visual** (login + layout + dashboard).

## URL local

- `https://www.connect.lab/erp` (redireciona para `/erp/login` ou `/erp/dashboard`).
- Atenção: o compose `connect-erp` monta o checkout principal (`www/connect`, branch `main`). O que está em `claude-layout` só aparece no connect.lab depois do merge ou com o compose rodando a partir do worktree (ver "Como testar").
- Prévia estática para aprovação (gerada pelo próprio tema): https://claude.ai/artifact/DaVWJZRTTejtFhi7mK5GZH

## Números

| Item | Quantidade |
|---|---:|
| Rotas no inventário da auditoria | 172 |
| Removidas (fornecedor SaaS, ADR-002) | 3 (AP-169, AP-170, AP-172) |
| Fundidas por duplicidade | 3 (AP-055→052, AP-077→051, AP-078→050) |
| Páginas validadas e com rota registrada | 166 |
| Visões gerais de módulo (hubs) | 10 |
| Rotas de acesso | 6 (`/erp`, `/erp/login` GET/POST, `/erp/sair` POST, `/erp/recuperar-senha`, `/erp/seguranca/codigos`) |
| Páginas com interface específica concluída | 1 (Dashboard) + login reaproveitado do Studio |
| Páginas com estado "em construção" específico | 165 |
| Rotas cobertas pelo smoke de renderização | 166 páginas + 10 hubs + estados + login |
| Rotas testadas por HTTP no connect.lab | 0 (bloqueado no ambiente; ver Pendências) |

Matriz completa (módulo, página, rota, componente, layout, ações, validação, implementação, teste): [`docs/ERP_V1_ROUTE_MATRIX.md`](ERP_V1_ROUTE_MATRIX.md), gerada por `tools/erp-catalog.py`.

## O que foi feito

- **Carregamento do tema**: `moves_container_path/url('erp', …)` resolvem `container/erp/{tema}/v1` (alias `default` → `connect`) e `index.php` inclui o `routes.php` do ERP. São as mesmas linhas do A1 da frente `claude-dev`, para o merge ser limpo.
- **Rotas**: `container/erp/connect/v1/routes.php` registra tudo pelo MovesCode Router (`Source\Core\Router`), a partir do catálogo `components/layout/catalog.php`.
- **Login**: a tela do Studio (`container/cms/studio/v1/components/login/login.php`) é reaproveitada com `loginEnvironment = erp`; mesmo `Source\Models\Auth`, CSRF, limite de tentativas (`erp_login`, 8 em 5 min), lembrar e-mail e mostrar/ocultar senha. O 2FA usa o fluxo existente em `/studio/login/2fa?next=erp`; `Studio\TwoFactor` passou a aceitar `next=erp`, exige `erp.access` e leva a `/erp/dashboard`. Ao ativar o 2FA obrigatório, os códigos de recuperação aparecem em `/erp/seguranca/codigos`. Sem credencial fixa e sem bypass.
- **Autorização**: toda rota do ERP exige sessão completa (`authUser` só existe depois do 2FA) e `erp.access`; sem permissão, 403 sem a navegação. Logout por POST com CSRF.
- **Layout único** (`default.php`): sidebar verde Connect com 12 grupos em `<details>`, item ativo, recolher (lembrado no navegador) e gaveta no celular com `inert`, Escape e foco; navbar com título, breadcrumbs, pesquisa das páginas (Ctrl + K), notificações, configurações, menu do usuário e Sair.
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
| Páginas de sistema | `pages/system/hub.php`, `pages/system/pending.php`, `pages/system/state.php`, `pages/auth/recovery-codes.php` |

## Testes executados

| Comando | Resultado |
|---|---|
| `php -l` em todos os PHP alterados/novos | sem erros |
| `node --check container/erp/connect/v1/assets/js/erp.js` | sem erros |
| `python3 tools/erp-catalog.py` | 166 páginas, 3 removidas, 3 fundidas, nenhuma rota duplicada |
| `VENDOR=/Volumes/MAC\ HD/Docker/xampp/www/connect/vendor php tests/erp/render-smoke.php` | **1.821 verificações, 0 falhas** |

O smoke renderiza, sem banco, pelo mesmo `Source\Core\View` do controller: dashboard (indicadores, gráficos, tabela, modal, aviso de demonstração), os 10 hubs (todos os links), as 165 páginas restantes (sidebar, navbar, breadcrumbs, título, item ativo, um `<main>`, logout com CSRF, rastreabilidade), todos os links internos contra as rotas registradas, estados 403/404, códigos de recuperação, dashboard com demonstração desligada e o login do ERP e do Studio (o do Studio continua igual). Avisos PHP contam como falha.

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

1. **Aprovação da identidade visual** (login + dashboard) antes de replicar nos módulos.
2. **Interfaces específicas das 165 páginas**: hoje cada rota mostra o estado "em construção" com o que a página terá e a ficha de origem. As fichas `docs/specs/pages/ap-*.md` ainda estão com campos PENDENTE; as telas seguirão o tipo (listagem, formulário, relatório, importação, configuração, documento, gráfico) sem inventar campos.
3. **Permissões por módulo** (`erp.<módulo>.view`/`.manage`) não existem em `access_permissions`; hoje só `erp.access` é exigido. Precisa de migration e seed (frente backend).
4. **Recuperação de senha**: `Auth::forget()` depende do template `container/mail/default/forget.php` (ausente) e de rota de redefinição; além disso responde de forma diferente para e-mail cadastrado e não cadastrado. A tela `/erp/recuperar-senha` explica a pendência.
5. **Gestão do 2FA** fica no Studio (`/studio/seguranca/2fa`), que exige `studio.access`; quem só tem `erp.access` precisa de uma tela própria no ERP.
6. **Bundle via MovesCode Compress** de `erp.css`/`erp.js` para `public/` (hoje servidos direto do tema, como no ADR 0007 da frente claude-dev).
7. **Merge com `claude-dev`**: lá existem, sem commit, `default.php`, `erp.css` e páginas de Condomínios em `container/erp/connect/v1`. No merge, a moldura deste branch prevalece e as views de Condomínios (`Condominiums` controller) passam a usar `$this->layout('default')` com os dados de `Panel::layoutData()`.
8. **Testes HTTP e de navegador** (rotas, autenticação, permissões, console JS, 390/768/1440 px) no connect.lab.

## Próximas etapas

1. Djalma aprova ou ajusta login + dashboard.
2. Construir as interfaces por tipo, começando por Condomínios (listagem, cadastro, detalhe, edição, vínculos), Unidades e pessoas, e Financeiro.
3. Testes HTTP automatizados de todas as rotas autenticadas no container e relatório de cobertura atualizado.
