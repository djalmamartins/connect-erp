<!-- markdownlint-disable MD024 -->
# 0217 — Renovação de contratos em lote, com reajuste opcional

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0051 (contratos);
  - 0204 (reajuste de lançamentos fixos: mesmo percentual e arredondamento);
  - 0213 e 0216 (mesmo padrão: regravar pelo formulário, tudo ou nada e prévia).

## Contexto

O painel operacional conta os contratos vencendo e os vencidos (`OperationalDashboard`, `contracts_renewal` e
`contracts_expired`), mas a renovação é feita contrato por contrato no formulário. Cada um pede a nova data de fim e,
no aniversário, o valor mensal reajustado pelo índice do contrato.

Na virada do ano são dezenas de contratos (portaria, limpeza, elevador, jardinagem) com o mesmo prazo e o mesmo índice.

## Decisão

Rota nova: `POST /api/v1/contracts/renew` com `{condominium_id, contract_ids, months, percent?, reason, dry_run?}`. O
service é `ContractRenewal`. A rota fica antes do laço dos cadastros, para não colidir com `/contracts/{id}`.

### Reuso

- **`RecurringReadjustment::basisPoints` e `::readjusted`:** o mesmo percentual (de −50% a 100%, até 2 casas) e o
  mesmo arredondamento ao centavo do reajuste de lançamentos fixos.
- **`RegistryService::save` do cadastro `contracts`:** cada contrato é regravado só com o fim e o valor trocados.
  Valem as regras e a auditoria do formulário: fim depois do início, valor positivo e fornecedor ativo.

### Regras puras

- **`renewedEnd()`:** soma os meses ao fim atual. O dia que não existe no mês de destino vira o último dia do mês
  (31/01 + 1 mês = 28/02).
- **`problem()`:** recusa o contrato:
  - não encontrado no condomínio;
  - encerrado ou cancelado;
  - de prazo indeterminado (sem fim);
  - marcado "sem reajuste" quando veio percentual.

### Gravação

- **Tudo ou nada:** numa transação com os contratos travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` e `_contract`, ou os erros do formulário.
- **Prévia:** `dry_run` passa pelo formulário e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `renew` por contrato, só quando grava. Ele guarda o fim e o
  valor antes e depois, os meses, o percentual e o motivo.
- **Motivo:** obrigatório, de 5 a 255 caracteres.
- **Limites:** de 1 a 60 meses e até 200 contratos por vez.
- **Lançamento fixo:** o que paga o contrato não muda. O reajuste dele continua na ADR 0204 (`POST
  /recurring-entries/readjust`).
- **Nada é enviado.**

### Acesso

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.contracts.view` e `erp.contracts.manage`; sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

## Consequências

- **`Unit\ContractRenewalTest`:**
  - as regras puras, incluindo o fim de mês e o ano bissexto;
  - validação e escopo (404);
  - as recusas antes do formulário, sem transação aberta;
  - contrato de reuso;
  - controller e rota.
- **`Integration\ContractRenewalTest`** (MariaDB, banco `*_test`):
  - a prévia sem gravar;
  - a renovação com reajuste de 4,62% (arredondamento conferido) e a auditoria;
  - a prorrogação sem percentual;
  - "sem reajuste" com percentual desfazendo o lote;
  - escopo.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.
- **Sem tela nova neste patch.** A lista de contratos pode ganhar a seleção com "Renovar"; quando ganhar, vem o artigo
  da Central de Ajuda.
