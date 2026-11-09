# 0026 — Studio (CMS) e Help Desk com a moldura do ERP, e Studio em host próprio

Data: 2026-11-09 · Status: aceito (pedido do Djalma em 08/11/2026: "vamos mudar o cms e helpdesk
para o layout igual do ERP" e "studio.connect.lab"; decisões tomadas por delegação dele)

## Contexto

O ERP ganhou a moldura do padrão Realizze PJ (ADR 0010 e a fatia de layout): sidebar verde
profunda, logotipo Connect, Inter, navbar com título e trilha. O Studio (CMS, `/studio`) e o
Help Desk (`/helpdesk`) ainda usavam a moldura MovesOS (marca MOVES, roxo no Studio, azul no
Help Desk, modo escuro). Os dois compartilham `container/cms/studio/v1/layouts/studio.php`
e dezenas de components que dependem das classes `studio-*`.

## Decisão

- **Uma moldura só para Studio e Help Desk**, em `layouts/studio.php`, no desenho do ERP:
  logotipo do site (`site_logo_url()`) e marca recolhida, botão de recolher na sidebar,
  caixa de contexto "Connect · Studio" / "Connect · Help Desk" (abre a troca de ambiente),
  menu agrupado, perfil no rodapé da sidebar, navbar com título e trilha, busca, ajuda,
  notificações, configurações e menu do usuário (com Segurança e 2FA).
- **Camada visual `assets/css/connect-shell.css`**, carregada depois de `studio.min.css`:
  aponta os tokens `--studio-*`/`--org-*` para os valores do design system do ERP e redesenha a
  moldura. Os components não foram reescritos; herdam verde, Inter e superfícies do ERP pelos
  tokens. O bundle `studio.min.css` não muda (a build só roda com `MOVESOS_BUILD_ASSETS=1`).
  Os valores espelham `container/erp/connect/v1/assets/css/erp.css`; mudou lá, muda aqui.
- **Sem modo escuro**, como o ERP: o alternador saiu e o tema fica claro.
- **Studio em host próprio** (`studio.connect.lab`, `studio.<domínio>`), como o ERP (ADR 0010):
  `studio_host()` em `source/Boot/Helpers.php`. Nesse host só existem Studio, Help Desk e a
  recuperação de senha; a raiz redireciona para `/studio` e erros vão para `/studio/ops/...`.
  As rotas continuam em `/studio` e `/helpdesk` (os components montam links com esses
  prefixos). `site_home_url()` leva "Visualizar site" ao host `www.` correspondente.
- **Reset do 2FA na tela** (API do ADR 0025): no perfil do usuário (`components/users/form.php`),
  quem tem `security.2fa.manage` vê "Redefinir 2FA" para outro usuário com 2FA ativo; o botão
  chama `DELETE /api/v1/users/{id}/2fa` com o token CSRF. As regras continuam só no servidor.
- Login segue o do Studio (decisão de 08/11), sem mudança aqui.

## Consequências

- `tests/studio/render-smoke.php` renderiza a lista de páginas real nos três contextos e
  verifica a moldura; o teste unitário `StudioErpShellTest` cobre o host e o layout.
- No lab, o host `studio.connect.lab` precisa de entrada no `hosts`, `ServerAlias` no vhost do
  Apache e certificado (mkcert), como foi feito para `erp.connect.lab`.
- Reverter = remover o `<link>` da camada e restaurar o markup anterior do layout.

## Atualização (09/11/2026, pedido do Djalma)

- **Cores por ambiente**: a estrutura continua a do ERP, mas o verde saiu. O Studio usa tons de
  roxo (`--cx-brand: #6b1fa8`, sidebar `#2a0f40`) e o Help Desk usa azul (`--cx-brand: #2563eb`,
  sidebar `#0f1f45`). As paletas ficam em `connect-shell.css`, nos blocos
  `body.studio-erp[data-environment="studio|helpdesk"]`. Só as medidas e a tipografia continuam
  espelhando `erp.css`.
- **Menu igual ao do ERP**: links diretos (Dashboard / Fila de chamados) e grupos em `<details>`
  com submenu. O grupo da página atual abre sozinho; com o menu recolhido, clicar num grupo expande
  o menu.
- **Cabeçalho da sidebar**: o tipo do módulo fica em cima e o nome embaixo. São "CMS / Moves Studio",
  "Atendimento / Moves Help Desk" e, no ERP, "ERP / Connect ERP".
- **AP-021**: a lista de lançamentos fixos ganhou o botão "Gerar lançamentos do mês", que chama
  `POST /api/v1/recurring-entries/generate` (ADR 0027) com o token CSRF e mostra o resultado
  (gerados, já existentes e com erro). O botão só aparece com `erp.recurring_entries.manage` e
  `erp.entries.manage`, e a API valida as duas de novo.
- **Logotipo Moves** no Studio e no Help Desk, enviado pelo Djalma e salvo em
  `container/cms/studio/v1/assets/images/moves-logo.svg`. Na sidebar escura entram a versão branca
  (`moves-logo-white.svg`) e o símbolo recortado (`moves-mark-white.svg`, para o menu recolhido),
  porque o fim roxo do degradê some no fundo roxo.
- **Telas no padrão do ERP**: cabeçalhos, cards, botões, campos (brancos com contorno), tabelas e
  selos de status seguem o `erp.css` pela camada `connect-shell.css`, sem reescrever os components.
  O dashboard foi refeito com indicadores, gráfico de acessos dos últimos dias, ações rápidas e
  artigos recentes (componentes `cx-*`).
