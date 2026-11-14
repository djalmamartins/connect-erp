<!-- markdownlint-disable MD024 -->
# 0292 — Documentos emitidos para as unidades

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0044 (documentos da unidade: certidão de débitos, declaração de inadimplência, comprovante de quitação e carta de
    anuência, AP-123 a AP-130);
  - 0057 (recibo, AP-040);
  - 0290 (a janela de meses do relatório de descontos).

## Contexto

Os documentos da unidade ficam gravados com o tipo, o resultado (nada consta ou com débitos), o total apontado e o código
de verificação. A lista mostra um por vez.

A administradora não vê, de uma vez:

- quantos documentos de cada tipo saíram no período, e quantos foram cancelados;
- quantos saíram com débito, e o valor apontado;
- como isso se distribui pelos meses.

Busquei `erp_unit_documents` em `source/` (emissão, recibos, estorno e verificação), as rotas `/reports/*` e o README das
ADRs 0200 a 0291. Nenhum relatório resume os documentos emitidos.

## Decisão

Rota nova: `GET /api/v1/reports/unit-documents?condominium_id=&months=`, logo depois de
`POST /assemblies/cancel-stale-drafts`. O service é `UnitDocumentOverview`, em `Source\Services\Erp`.

### Reuso

- **Tipos e rótulos:** `UnitDocuments::ALL_KINDS` (os quatro documentos e o recibo). O teste unit confere que batem com a
  enum `kind` da migration.
- **Situação e resultado:** `issued` ou `cancelled`, e `clear` ou `debts`, como a emissão grava.
- **Permissão:** a da lista de documentos da unidade (`erp.unit_documents.view`).
- **Janela:** `DiscountOverview::months` (ADR 0290), os últimos N meses (padrão 12, de 1 a 36).
- **Relógio:** os meses do banco (`SELECT NOW()`), porque `created_at` foi gravado por ele.

### Regra pura `summarize()`

- **`rows`:** uma linha por tipo, sempre todos, com:
  - os emitidos válidos e os cancelados;
  - os emitidos nada consta e com débito, que somam os emitidos;
  - o total apontado nos emitidos com débito;
  - as unidades distintas com emitido no tipo.
  `totals` soma as linhas.
- **`by_month`:** os emitidos por mês da janela, sempre todos, somando `totals.issued`.
- **`cancelled_ids`:** os cancelados, do mais recente para o mais antigo, até 200, com `truncated`.
- **`unknown`:** documento de tipo fora da lista; fica de fora das linhas.
- **Ficam de fora:** documento fora da janela, de outro condomínio e de unidade de outro condomínio.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.unit_documents.view`. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** tipos, meses, contagens, valores em centavos e ids de documentos. Não saem código de verificação,
  conteúdo, finalidade, unidade, motivo nem quem emitiu.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\UnitDocumentOverviewTest`:**
  - os tipos de `ALL_KINDS` conferidos com a enum da migration;
  - linhas por tipo com totais iguais à soma, o resultado somando os emitidos e os meses somando os emitidos;
  - documento fora da janela, de outro condomínio e de unidade de outro condomínio de fora;
  - os cancelados à parte, em ordem, com o corte em 200, e o tipo desconhecido contado à parte;
  - nenhum código, conteúdo, finalidade nem unidade;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\UnitDocumentOverviewTest`** (MariaDB, banco `*_test`):
  - documentos em `erp_unit_documents`, com os meses pelo relógio do banco;
  - somente leitura, sem código nem conteúdo;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
