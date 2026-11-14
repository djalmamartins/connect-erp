<!-- markdownlint-disable MD024 -->
# 0294 — Situação das portarias

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0042 (portaria: portarias, previsão de visita, registro de acessos e entregas, AP-131 a AP-135);
  - 0230 (movimento da portaria: os acessos por tipo de pessoa);
  - 0166 (Registro de Entregas: a encomenda aguardando retirada).

## Contexto

O cadastro de portarias guarda o tipo (presencial, remota ou híbrida), os turnos e a situação. Os acessos e as encomendas
apontam a portaria em que foram registrados. O movimento da portaria (ADR 0230) resume os acessos por tipo de pessoa.

A administradora não vê, por portaria:

- quantas estão ativas de cada tipo;
- quanto cada uma registrou no período, e quais não registraram nada;
- quantas encomendas esperam retirada em cada uma, e desde quando.

Busquei `erp_gatehouses` em `source/` (só a definição do cadastro a usa), as rotas `/reports/*` e o README das ADRs 0200
a 0293. Nenhum relatório mostra a situação das portarias.

## Decisão

Rota nova: `GET /api/v1/reports/gatehouses?condominium_id=&days=`, logo depois de `POST /taxes/deactivate-redundant`. O
service é `GatehouseOverview`, em `Source\Services\Erp`.

### Reuso

- **Cadastros do registro** (`RegistryService::definition`):
  - `gatehouses`: a tabela, a permissão e os tipos do campo `kind`;
  - `access_logs` e `deliveries`: as tabelas e as permissões.
- **Regras das listas:** sem os excluídos; acesso cancelado fica de fora; encomenda aguardando retirada é `status =
  active`, como no Registro de Entregas. O teste unit confere a enum da migration.
- **Relógio:** o dia do banco (`SELECT NOW()`), para comparar com as datas gravadas.

### Regra pura `summarize()`

- **`rows`:** uma linha por tipo do cadastro, sempre todos, com:
  - as portarias ativas e as inativas;
  - os acessos registrados nas ativas nos últimos `days` dias (padrão 30, de 1 a 365);
  - as encomendas aguardando retirada nelas, e a mais antiga.
  `totals` soma as linhas.
- **`elsewhere`:** os acessos e as encomendas sem portaria ou numa portaria inativa ou removida, à parte. `all_entries` e
  `all_pending` somam as linhas e o `elsewhere`.
- **`idle_ids`:** as portarias ativas sem acesso no período, até 200, com `truncated`.
- **Ficam de fora:** acesso fora do período, cancelado ou excluído, encomenda retirada ou cancelada, portaria excluída e
  outro condomínio.

### Acesso e dados

- **Permissão:** exige `erp.access` e a permissão de ver de cada cadastro que entra na conta (`erp.gatehouses.view`,
  `erp.access_logs.view` e `erp.deliveries.view`). Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** tipos, contagens, datas e ids de portarias. Não saem nome de portaria, responsável, telefone,
  pessoa nem documento.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\GatehouseOverviewTest`:**
  - linhas por tipo com totais iguais à soma, e o `elsewhere` à parte somando com as linhas;
  - o período nos limites e acesso cancelado ou excluído, encomenda retirada, portaria excluída e outro condomínio de
    fora;
  - as portarias sem acesso e o corte em 200;
  - nenhum nome, responsável, telefone, pessoa nem documento;
  - tipos vindos do cadastro, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\GatehouseOverviewTest`** (MariaDB, banco `*_test`):
  - portarias, acessos e encomendas de verdade, com as datas pelo dia do banco;
  - somente leitura, sem nome nem pessoa;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
