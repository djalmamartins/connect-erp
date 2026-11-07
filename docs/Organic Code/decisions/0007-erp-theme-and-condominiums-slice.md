# 0007 — Tema ERP em container/erp/connect/v1 e primeira fatia de Condomínios

Data: 2026-11-07 · Status: aceito (aguarda smoke de `/erp` no lab)

## Contexto

A auditoria (`docs/architecture/CONNECT_ERP_V1.md`, C2) mostrou que
`moves_container_path('erp', ...)` apontava para `container/apps/erp/{tema}` e que o
`index.php` incluía `default.php` do ERP como rotas. O tema `container/erp/connect/v1`
nunca era carregado e `/erp` respondia 404. Os controllers `Erp/Connect/*` existentes
dependem do modelo legado (sessão `authCondo`, `AppWallet`, `AppSession`).

## Decisão

- **A1.** `erp` resolve para `container/erp/{tema}/v1` em `moves_container_path()` e
  `moves_container_url()`. Alias `default` → `connect` (valor gravado pela migration
  legada `20260829_canonical_container_paths.sql`), por isso não há migration nova. O nome
  do tema passa por `moves_erp_theme()`, restrito a `[a-z0-9_-]` (padrão `connect`). O
  `index.php` inclui `routes.php` do ERP. `residents` não foi alterado.
- **A2.** `default.php` é a moldura única (sidebar, navbar, seção `content`), sem
  `layouts/`. `routes.php` registra o grupo `/erp` no namespace
  `Source\Controllers\Erp\Connect` e devolve grupo e namespace a `null` no fim.
- Novo controller `Erp\Connect\Condominiums`, independente do `Erp` legado (que segue
  congelado, C11). Fluxo: rota → controller → `CondominiumScope` → MariaDB, o mesmo
  serviço da API `/api/v1/condominiums`.
- Rotas: `GET /erp` (redireciona), `GET /erp/condominios`, `GET /erp/condominios/{id}`.
  Exigem sessão autenticada (o 2FA já é concluído antes de `authUser`), `erp.access` e
  `erp.condominiums.view`. Fora do escopo do usuário a resposta é 404. Sem permissão, 403.
- Somente leitura: cadastro e edição aguardam a spec AP-004, cujos campos estão PENDENTE.
- Estilos em `assets/css/erp.css`, ligados direto pela moldura. Não há `assets/js/`: o
  `Services/Minify/Erp.php` reconstruiria o bundle (jQuery, Highcharts…) a cada requisição
  local. O bundle via MovesCode Compress fica com a frente `claude-layout`.
- Login: usuário sem sessão vai para `/studio/login`, que é o login do núcleo existente.

## Consequências

- Os controllers legados `Erp/Connect/*` passam a resolver views para o novo tema, mas
  continuam sem rota.
- Depois do login, o Studio redireciona para `/studio/dash`. Quem tem acesso só ao ERP
  precisa abrir `/erp` manualmente até existir uma decisão sobre login do ERP.
- Testes: `tests/legacy/Unit/ErpThemeTest.php` (resolução de caminho, contrato do tema,
  rotas, escape, estados vazio/erro/detalhe). Integração HTTP de `/erp` ainda não existe.
