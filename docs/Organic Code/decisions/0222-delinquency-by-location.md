<!-- markdownlint-disable MD024 -->
# 0222 — Inadimplência por bloco ou andar

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0040 (encargos sugeridos da inadimplência);
  - 0207 (mesmas faixas de atraso);
  - 0218 e 0220 (mesmo formato de relatório).

## Contexto

O relatório de inadimplentes (`CollectionReports::delinquents`, nas visões geral, unidade, taxa e responsável) e o
painel mostram a inadimplência por condomínio ou por unidade. O conselho e a administradora querem saber onde ela se
concentra: uma torre, os andares de uma obra de fachada, um bloco com rateio diferente. Querem isso sem expor quem deve,
para levar à assembleia.

Nada no `source/` agrupa a inadimplência pelo bloco ou pelo andar do cadastro de unidades.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/delinquency-by-location?condominium_id=&by=block|floor&as_of=`. O
service é `DelinquencyByLocation`.

### Reuso

- **`Agreements::delinquency`:** as mesmas contas vencidas em aberto, encargos sugeridos (`BillingRules` e
  `LateCharges`) e dias de atraso do relatório de inadimplentes.
- **`Agreements::bucket`:** as mesmas faixas de atraso (1-30, 31-60, 61-90 e 90+).

### Regra pura `group()`

- **O que agrupa:** as unidades em atraso, pelo bloco ou pelo andar do cadastro.
- **Rótulos:** bloco vazio vira "Sem bloco"; andar vazio, "Sem andar"; conta sem unidade, "Sem unidade".
- **Por grupo:**
  - unidades ativas do grupo e unidades em atraso;
  - taxa (% das unidades do grupo em atraso);
  - contas, valor, encargos e total;
  - maior atraso;
  - unidades por faixa, pelo maior atraso de cada unidade.
- **Ordem:** maior total primeiro.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access` e `erp.agreements.view`, as mesmas do relatório de inadimplentes; sem uma delas,
  403 (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados:** saem só os grupos e as somas. Não saem nome, documento ou contato, nem rótulo de unidade ou descrição de
  lançamento.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\DelinquencyByLocationTest`:**
  - as regras puras: rótulos, taxa, faixas, ordem e conta sem unidade;
  - validação e escopo (404);
  - contrato de reuso: somente leitura e sem dado da unidade;
  - controller e rota.
- **`Integration\DelinquencyByLocationTest`** (MariaDB, banco `*_test`):
  - contas a receber pelo formulário em dois blocos e dois andares;
  - uma conta paga, que não entra;
  - uma conta sem unidade;
  - a taxa e as faixas por bloco e por andar;
  - nenhum rótulo ou descrição no JSON;
  - nenhuma linha nova na auditoria;
  - escopo.
- **Sem tela nova neste patch.** O relatório de inadimplentes pode ganhar a visão "Por bloco/andar"; quando ganhar, vem
  o artigo da Central de Ajuda.
