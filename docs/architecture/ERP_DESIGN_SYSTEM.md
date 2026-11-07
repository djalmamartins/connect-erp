# Design system do Connect ERP v1

Camada única de componentes do ERP: `container/erp/connect/v1/assets/css/erp.css` e `assets/js/erp.js`. Separado do design system do Studio (ADR-003).

## Referência

- **Estrutura**: padrão do Realizze Empresa (shell com sidebar e navbar, menus agrupados em `<details>`, cabeçalho de página com ações, cards, tabelas em região rolável, estados vazios honestos). Nada da marca, dos textos ou dos dados do Realizze foi copiado.
- **Identidade**: a do Studio (ADR-003: neutros, Gotham, sidebar clara, raios e sombras), com o **dourado Connect** como cor principal no lugar do roxo do Studio. Decisão de Djalma em 2026-11-07.

## Tokens

| Grupo | Tokens |
|---|---|
| Marca (dourado) | `--erp-brand` `#c9a227` (fundos), `--erp-brand-strong` `#a8841a` (hover), `--erp-brand-soft` `#fbf5e3`, `--erp-brand-line`, `--erp-brand-ink` `#7d6210` (texto dourado sobre branco), `--erp-on-brand` `#172038` (texto sobre o dourado), `--erp-ring` |
| Sidebar | `--erp-sidebar` branco, `--erp-sidebar-ink` `#69717e`, `--erp-sidebar-muted`, `--erp-sidebar-hover`, `--erp-sidebar-active` (= brand-soft) |
| Superfícies | `--erp-canvas` `#f7f8fa`, `--erp-surface`, `--erp-surface-muted`, `--erp-border` `#e7e9ee`, `--erp-border-strong` |
| Texto | `--erp-text` `#172038`, `--erp-muted` `#74809a`, `--erp-faint` |
| Estados | `--erp-success`, `--erp-warning`, `--erp-danger`, `--erp-info`, `--erp-neutral` (+ `-soft`), mesmos valores do Studio |
| Espaço | `--erp-space-1` … `--erp-space-8` (4 a 40 px) |
| Forma | `--erp-radius-sm` 7, `-md` 10, `-lg` 14, `-pill` |
| Elevação | `--erp-shadow-card`, `--erp-shadow-raised`, `--erp-shadow-modal` |

Contraste: texto branco sobre o dourado não passa no AA, por isso botões e itens dourados usam `--erp-on-brand` (azul-marinho do Studio) e textos dourados usam `--erp-brand-ink`.

Tipografia: Gotham (fontes em `container/shared/assets/organic/fonts`) com fallback Inter/sistema, base 14 px; título de página 24 px (21 px no celular), título de card 16 px, rótulos 12–13 px. Números com `tabular-nums`.

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
