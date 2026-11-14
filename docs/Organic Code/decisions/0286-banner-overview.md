<!-- markdownlint-disable MD024 -->
# 0286 — Situação dos banners do aplicativo

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0085 (configurações do ERP: banners do aplicativo, AP-156, texto curto por período; sem condomínio, valem para
    todos);
  - 0096 e 0097 (banners só com texto simples).

## Contexto

Os banners são avisos curtos no topo do aplicativo, cada um com início, fim, posição e situação (ativo ou inativo). O
cadastro lista um por vez.

A administradora não vê, de uma vez, para um condomínio:

- o que está no ar hoje, somando os banners dele e os de todos os condomínios;
- o que ainda vai entrar;
- o que já acabou e continua ativo (esquecido no cadastro);
- o que acaba nos próximos dias.

Busquei `erp_banners` em `source/` (só a definição do cadastro a usa), as rotas `/reports/*` e o README das ADRs 0200 a
0285. Nenhum relatório trata dos banners.

## Decisão

Rota nova: `GET /api/v1/reports/banners?condominium_id=`, logo depois de `POST /imports/discard-stale`. O service é
`BannerOverview`, em `Source\Services\Erp`.

### Reuso

- **Cadastro:** `RegistryService::definition('banners')` dá a tabela e a permissão (`erp.settings`). O escopo do cadastro
  é opcional: sem condomínio, vale para todos, e o relatório soma os dois.
- **Situação:** a enum `status` de `erp_banners` (ativo ou inativo). O teste unit confere a enum na migration.
- **Relógio:** o dia do banco (`SELECT NOW()`), para comparar com as datas gravadas.

### Regra pura `summarize()`

- **`state()`:** inativo (status inativo, antes das datas), agendado (começa depois de hoje), encerrado (acabou antes de
  hoje) ou no ar. Começar ou acabar hoje é estar no ar.
- **`rows`:** uma linha por alcance de `SCOPES` (deste condomínio e de todos), sempre os dois, com `banners` e a contagem
  por situação de `STATES`, que somam `banners`. `totals` soma as linhas; `showing_now` é `totals.showing`.
- **Ids:** até 200 cada, com `truncated`:
  - `showing_ids`, na ordem de exibição (posição, sem posição no fim, e início);
  - `ending_soon_ids`, no ar e acabando em até `SOON_DAYS` (7) dias, do que acaba antes;
  - `ended_active_ids`, encerrados ainda ativos, para inativar.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.settings.view`, a de ver o cadastro de banners. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422. Banner de outro condomínio e apagado ficam de fora.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** alcances, situações, contagens, a data e ids. Não saem título, texto nem quem cadastrou ou alterou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\BannerOverviewTest`:**
  - as situações nos limites do dia;
  - contagens por alcance, com totais iguais à soma das linhas e situações somando o total;
  - outro condomínio e apagado de fora;
  - os ids na ordem e o corte em 200;
  - nenhum título nem texto;
  - a validação, o escopo, o contrato, a definição do cadastro, o controller, a rota e a OpenAPI.
- **`Integration\BannerOverviewTest`** (MariaDB, banco `*_test`):
  - banners em `erp_banners`, com as datas pelo dia do banco;
  - somente leitura, sem título nem texto;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
