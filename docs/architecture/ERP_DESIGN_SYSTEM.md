# Design system do Connect ERP v1

Camada única de componentes do ERP: `container/erp/connect/v1/assets/css/erp.css` e `assets/js/erp.js`. Separado do design system do Studio (ADR-003).

## Referência

Padrão visual do Realizze Empresa (contexto PJ do App): shell com sidebar em verde profundo sobre canvas claro, menus agrupados em `<details>`, cabeçalho de página com ações, cards suaves, tabelas em região rolável, estados vazios honestos. Nada da marca, dos textos ou dos dados do Realizze foi copiado; a identidade é Connect (verde `#063d32`, dourado `#c5a131`, logotipo Connect).

## Tokens

| Grupo | Tokens |
|---|---|
| Marca | `--erp-brand` `#0a7a55`, `--erp-brand-strong`, `--erp-brand-soft`, `--erp-brand-ink` `#063d32`, `--erp-accent` `#c5a131` |
| Sidebar | `--erp-sidebar` `#063d32`, `--erp-sidebar-ink`, `--erp-sidebar-muted`, `--erp-sidebar-hover`, `--erp-sidebar-active` |
| Superfícies | `--erp-canvas` `#eef5f1`, `--erp-surface`, `--erp-surface-muted`, `--erp-border`, `--erp-border-strong` |
| Texto | `--erp-text`, `--erp-muted`, `--erp-faint` |
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
