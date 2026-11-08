# Design system do Connect ERP v1

Camada única de componentes do ERP: `container/erp/connect/v1/assets/css/erp.css` e `assets/js/erp.js`. Separado do design system do Studio (ADR-003).

## Referência

- **Visual**: igual ao Realizze PJ (contexto Empresa do App Realizze): sidebar verde profunda sobre canvas verde-claro, Inter, menus agrupados em `<details>`, cards suaves, tabelas em região rolável, estados vazios honestos. Decisão de Djalma em 2026-11-08, que substitui a identidade do Studio com dourado (2026-11-07).
- **Marca**: o logotipo do site da Connect, o mesmo do cabeçalho da web (`site_logo_url()`, que usa o logo das configurações e cai em `logo-connect-condominios.svg`), em branco sobre a sidebar. Nada da marca, dos textos ou dos dados do Realizze foi copiado.
- **Login**: continua a tela do Studio, que Djalma pediu para reaproveitar.

## Tokens

| Grupo | Tokens |
|---|---|
| Marca | `--erp-brand` `#07884c`, `--erp-brand-strong` `#066f3e`, `--erp-brand-soft` `#eaf7ef`, `--erp-brand-line`, `--erp-brand-ink`, `--erp-on-brand` branco, `--erp-ring`; `--erp-accent` `#c5a131` (dourado Connect, só em detalhes) |
| Sidebar | `--erp-sidebar` `#0d2e27`, `--erp-sidebar-ink` `#d5e4dc`, `--erp-sidebar-muted` `#8fb0a2`, `--erp-sidebar-strong` branco, `--erp-sidebar-accent` `#7fe8b6`, `--erp-sidebar-hover`, `--erp-sidebar-active`, `--erp-sidebar-line` |
| Superfícies | `--erp-canvas` `#eef8f2`, `--erp-surface`, `--erp-surface-muted`, `--erp-border` `#dfe8e3`, `--erp-border-strong` |
| Texto | `--erp-text` `#121a31`, `--erp-muted` `#65746d`, `--erp-faint` |
| Estados | `--erp-success`, `--erp-warning`, `--erp-danger`, `--erp-info`, `--erp-neutral` (+ `-soft`) |
| Espaço | `--erp-space-1` … `--erp-space-8` (4 a 40 px) |
| Forma | `--erp-radius-sm` 8, `-md` 11, `-lg` 15, `-pill` |
| Elevação | `--erp-shadow-card`, `--erp-shadow-raised`, `--erp-shadow-modal` |

Tipografia: Inter com fallback de sistema, base 14 px; título de página 24 px (21 px no celular), título de card 16 px, rótulos 12–13 px. Números com `tabular-nums`.

Breakpoints: 1100 px (grades reduzem), 900 px (sidebar vira gaveta, pesquisa some), 640 px (uma coluna, ações em largura total).

## Componentes (classes)

Shell `erp-shell` · sidebar `erp-sidebar`, `erp-nav__*` · navbar `erp-navbar`, `erp-search`, `erp-dropdown`, `erp-user-chip` · `erp-breadcrumbs` · `erp-page-head` · botões `erp-btn` (`--primary`, `--ghost`, `--danger`, `--sm`, `.is-loading`) · campos `erp-field`, `erp-input`, `erp-select`, `erp-textarea`, `erp-check`, `erp-form__grid` (12 colunas, `erp-col-*`) · filtros `erp-filters`, `erp-chip` · tabelas `erp-table-wrap`, `erp-table`, `erp-sort`, `erp-pagination` · `erp-card`, `erp-kpi` · `erp-badge` (`--success`, `--warning`, `--danger`, `--info`) · `erp-demo-flag` · `erp-alert` · `erp-empty` · `erp-skeleton` · abas `erp-tabs` + `role="tab"` · modais `<dialog class="erp-modal">` · `erp-toast` · gráficos `erp-bars`, `erp-donut`, `erp-progress` · listas `erp-list`.

## Comportamentos (data-*)

`data-erp-collapse`, `data-erp-nav-open/close`, `data-erp-dropdown`, `data-erp-search`, `data-erp-modal-open="id"` / `data-erp-modal-close`, `role="tablist"`, `data-erp-table` (+ `data-per-page`, `data-erp-sort`, `data-erp-table-filter`, `data-erp-pager`), `form[data-erp-validate]`, `data-erp-pending="mensagem"` (ação sem backend: só avisa, nunca simula sucesso), `form[data-erp-loading]`. API: `window.ErpUI.toast(mensagem, tom)`.

## Regras

- Nenhum módulo redefine primitivas; variações novas entram em `erp.css` com prefixo `erp-`.
- Cor reforça o texto, nunca o substitui. Valores de demonstração levam `erp-demo-flag`.
- Foco visível, `prefers-reduced-motion`, `forced-colors`, tabelas roláveis com nome acessível e `tabindex="0"`, gráficos com tabela equivalente.
- Modo escuro: planejado, fora desta fase.
