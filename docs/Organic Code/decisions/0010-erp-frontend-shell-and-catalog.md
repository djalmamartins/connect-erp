# 0010 — Moldura do ERP, catálogo de rotas e login compartilhado com o Studio

Data: 2026-11-07 · Status: proposto (aguarda aprovação visual de Djalma) · Branch: `claude-layout`

## Contexto

O ERP não carregava (tema procurado em `container/apps/erp`, `default.php` lido como rotas). A missão do frontend pede todas as rotas do inventário (172) navegáveis, um layout único no padrão do Realizze Empresa e o login com a autenticação existente. Djalma pediu para reaproveitar a tela de login do Studio.

## Decisão

- Mesmas linhas do A1 (claude-dev) para o carregamento do tema; `routes.php` registra as rotas pelo MovesCode Router.
- Catálogo validado gerado por `tools/erp-catalog.py` a partir de `docs/specs/routes.json` em `components/layout/catalog.php` (dado do tema, não código): 166 páginas, 3 removidas por serem do fornecedor SaaS (ADR-002), 3 fundidas por duplicidade. Rotas próprias em `/erp/{módulo}/{página}`; `/erp/condominios` fica com a listagem AP-004 (contrato com a frente claude-dev).
- `default.php` é a única moldura; páginas usam `$this->layout('default')`. Controller `Erp\Connect\Panel` monta os dados da moldura; `Erp\Connect\Login` reaproveita a view de login do Studio e o fluxo 2FA de `Studio\TwoFactor` (`next=erp`).
- Dados de demonstração só em `database/fixtures/erp-demo/`, via `Services\Erp\DemoData`, desligáveis por `ERP_DEMO_DATA`.
- Design system próprio (`erp.css`/`erp.js`) igual ao Realizze PJ, com a logo do site da Connect (decisão de Djalma, 2026-11-08; substitui a identidade do Studio com dourado de 2026-11-07).

## Consequências

- Até a aprovação visual, as páginas além do Dashboard mostram um estado "em construção" específico (o que terão e a ficha de origem), sem dados simulados.
- Permissões por módulo, recuperação de senha e gestão do 2FA dentro do ERP ficam como pendências (`docs/ERP_V1_FRONTEND_STATUS.md`).
- No merge com claude-dev, a moldura deste branch prevalece sobre a moldura provisória de lá.
