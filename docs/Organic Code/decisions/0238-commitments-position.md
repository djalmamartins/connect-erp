<!-- markdownlint-disable MD024 -->
# 0238 — Posição dos empenhos

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0050 (cadastros de documentos: AP-128 empenhos), só como fonte dos dados;
  - 0232 e 0236 (mesmo padrão de relatório: regra pura, totais iguais à soma das linhas).

## Contexto

O cadastro de empenhos lista empenho por empenho: a despesa reservada antes do pagamento, liquidada depois com a data e,
se houver, o lançamento que a pagou. Nenhum relatório mostra:

- quanto está empenhado e ainda não liquidado, e há quanto tempo;
- quanto se liquidou no período, e em quanto tempo;
- quantas liquidações ficaram sem o lançamento que as pagou, um controle que o conselho fiscal cobra.

Busquei `erp_commitments` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro (0050) e a inativação de
fornecedores (que confere empenho em aberto) usam a tabela; nenhum relatório resume os empenhos.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/commitments?condominium_id=&from=&to=`. O service é
`CommitmentsPosition`, em `Source\Services\Erp`.

### Reuso

- **Situações do cadastro:** `RegistryService::definition('commitments')` (Empenhado, Liquidado e Cancelado).
- **Regra das listas:** sem os excluídos.
- **Período:** padrão do mês de `to`, até 366 dias, como nos relatórios 0226, 0228, 0230 e 0232.

### Regra pura `summarize()`

- **rows:** uma linha por situação (sempre as três) com os empenhos feitos no período (`committed_on` de `from` a `to`):
  `commitments` e `amount_cents`; `totals` é a soma das linhas.
- **settlement:** dos liquidados do período, `with_entry` e `without_entry` (com e sem o lançamento que pagou, somando
  `count`), `dated` (liquidação na data do empenho ou depois), `days_total`, `avg_days` (1 casa) e `max_days`.
  Liquidado sem data, ou com data antes do empenho, conta em `count` e fica fora do tempo.
- **open:** os empenhados ainda em aberto feitos até `to` (de qualquer período), pela idade em `to` (até 30 dias, 31 a
  60, 61 a 90, mais de 90), com `commitments` e `amount_cents`; `total` é a soma das faixas.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.commitments.view`, a mesma do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** só as situações, as contagens e os valores. A consulta nem lê descrição, fornecedor, documento ou
  observação; o lançamento é lido só como "tem ou não tem".
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\CommitmentsPositionTest`:**
  - a regra pura: as três situações, o tempo de liquidação (com a liquidação sem data), com e sem lançamento, as faixas
    de idade com as bordas e os totais;
  - a montagem no SQLite: os em aberto de antes do período só na idade, sem os excluídos, os de depois e os de outro
    condomínio, e sem descrição nem fornecedor;
  - validação, escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\CommitmentsPositionTest`** (MariaDB, banco `*_test`): empenhos gravados pelo cadastro (valor em reais
  pelo formulário), as linhas, a liquidação, a idade dos em aberto, nada na auditoria, sem descrição e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Posição dos empenhos"; quando ganhar, vem o artigo da
  Central de Ajuda.
