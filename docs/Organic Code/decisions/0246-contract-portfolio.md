<!-- markdownlint-disable MD024 -->
# 0246 — Carteira de contratos do condomínio numa data

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - ADR 0051 (cadastros operacionais: AP-119 contratos), só como cadastro reusado;
  - 0217 (renovação de contratos em lote) e 0245 (encerramento em lote);
  - 0244 (mesmo desenho de relatório por faixa de vigência, para os seguros).

## Contexto

O cadastro de contratos (AP-119) guarda a vigência, o valor mensal, o índice de reajuste e o contrato assinado. Hoje:

- o painel operacional conta os contratos vencendo e vencidos;
- o calendário de obrigações lista os fins;
- a renovação (0217) estende e o encerramento (0245) fecha.

Ninguém vê, porém, a carteira: quanto o condomínio tem contratado por mês, por índice de reajuste e por prazo, quanto é de
prazo indeterminado, quantos contratos estão sem o documento assinado e quais reajustes caem nos próximos 90 dias. A
administradora precisa disso na previsão orçamentária e na assembleia.

Busquei `erp_contracts` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro, o painel operacional, o
calendário de obrigações, a renovação e o encerramento usam a tabela; nenhum relatório trata da carteira.

## Decisão

Rota nova: `GET /api/v1/reports/contract-portfolio?condominium_id=&on=`. O service é `ContractPortfolio`, em
`Source\Services\Erp`.

### Reuso

- **Índices de reajuste:** os do cadastro (`RegistryService::definition('contracts')`).
- **Regra das listas:** sem excluídos.
- **Regra de anexo do cadastro:** o documento vale quando existe no Disco Virtual do mesmo condomínio e não foi removido.
- **Regra do encerramento (0245)** para `closable`: vigente no cadastro, com o fim anterior à data, fornecedor ativo e
  documento, se houver, no Disco Virtual.

### Faixas

A regra pura `bucket()` põe cada contrato vigente no cadastro numa faixa:

- `expired`: o fim é anterior à data `on` (padrão hoje; o próprio dia vale);
- `ending_0_30`, `ending_31_90`, `ending_over_90`: em vigor, com o fim em tantos dias;
- `open_ended`: em vigor, de prazo indeterminado;
- `not_started`: o início é depois da data.

A regra pura `summarize()` monta uma linha por faixa, sempre as seis, com `contracts`, `monthly_cents`, `by_readjustment`
(os índices do cadastro, somando `monthly_cents`) e `without_document`. `totals` é a soma das linhas. Fora das linhas:

- `closable`: os vencidos que o encerramento em lote (0245) pegaria;
- `readjustment_due`: os contratos em vigor com índice (não "Sem reajuste") cujo aniversário do início cai de `on` até
  `on` + 90 dias e não passa do fim, com a soma mensal. A regra pura `nextAnniversary()` usa o mesmo dia e mês a partir
  do ano seguinte ao início; 29/02 vira 28/02 nos anos sem o dia;
- `ended` e `cancelled`: os contratos encerrados e cancelados.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.contracts.view`, as mesmas do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só as faixas, os índices, as contagens e as somas. Não saem objeto, fornecedor, documento ou
  observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ContractPortfolioTest`:**
  - a regra pura das faixas, com o fim no próprio dia, 31 dias, o indeterminado e o futuro;
  - os aniversários: no próprio dia, no ano seguinte, 29/02 em ano comum e bissexto, e o limite de 90 dias;
  - o resumo: as seis linhas, as somas por índice, o total igual à soma das linhas, `closable` e `readjustment_due`;
  - a consulta, sem excluídos ou outro condomínio, com o documento removido ou de outro condomínio contado como sem
    documento, o fornecedor inativo fora de `closable`, e sem dado do contrato;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\ContractPortfolioTest`** (MariaDB, banco `*_test`):
  - contratos gravados pelo cadastro: vencido, vencendo, de prazo longo, indeterminado e um encerrado;
  - as faixas e o valor convertido pelo cadastro, por índice, e o reajuste a vencer;
  - somente leitura e sem dado do contrato;
  - `closable` igual à prévia do encerramento em lote (0245); o escopo.
- **Sem tela nova neste patch.** A tela de contratos pode ganhar o quadro da carteira; quando ganhar, vem o artigo da
  Central de Ajuda.
