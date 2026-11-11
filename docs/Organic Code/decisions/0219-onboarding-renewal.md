<!-- markdownlint-disable MD024 -->
# 0219 — Renovação em lote do checklist de chegada

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0013 (checklist de chegada e avisos);
  - 0218 (calendário de vencimentos);
  - 0217 (mesma soma de meses com fim de mês);
  - 0213, 0216 e 0217 (mesmo padrão: tudo ou nada, prévia e auditoria).

## Contexto

O calendário de vencimentos (ADR 0218) e os avisos do checklist (`OnboardingAlerts`) apontam o que vence: extintores,
hidrantes, SPDA, caixa d'água, dedetização, certidões, seguro predial e AVCB. Quando a empresa entrega vários
certificados no mesmo dia, cada item é atualizado um a um na tela do checklist, com a nova validade calculada à mão
pela periodicidade do catálogo.

## Decisão

Rota nova: `POST /api/v1/condominiums/{id}/onboarding/renew` com `{item_ids, renewed_on, expires_at?, dry_run?}`. O
service é `OnboardingRenewal`. A rota fica junto das outras rotas do checklist.

### Reuso

- **`OnboardingChecklist::update`:** cada item passa para "Em dia" com a nova validade. Ele aplica a validação do item,
  o `resolved_at`, a limpeza do controle de avisos (`alerted_situation`) e a auditoria `update`.
- **`renewal_months` do catálogo e `ContractRenewal::renewedEnd`:** a validade padrão, com a mesma soma de meses e
  ajuste de fim de mês da ADR 0217.

### Regras puras

- **`newExpiry()`:** a validade informada; sem ela, `renewed_on` + os meses do catálogo.
- **`problem()`:** recusa o item:
  - não encontrado no condomínio;
  - marcado "não se aplica";
  - sem periodicidade no catálogo (AVCB, item avulso) e sem a nova validade informada;
  - com a validade informada que não é depois da data da renovação.

### Gravação

- **Tudo ou nada:** numa transação com os itens travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  e `_item`, ou os erros do checklist.
- **Prévia:** `dry_run` passa pelo checklist e desfaz no fim.
- **Auditoria:** a `update` do checklist e um registro `renew` por item, só quando grava. Ele guarda a situação e a
  validade antes e depois e a data da renovação.
- **Validação:** `renewed_on` não pode ser futura; até 100 itens por vez.
- **Nada é enviado.**

### Acesso

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access` e `erp.condominiums.manage`, as mesmas do PATCH de um item; sem uma delas, 403
  (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

### Dados

Saem id, código, título, categoria, situação e validade. Não saem responsável nem observações.

## Consequências

- **`Unit\OnboardingRenewalTest`:**
  - as regras puras;
  - validação e escopo (404);
  - as recusas antes do checklist, sem transação aberta e sem gravar;
  - contrato de reuso;
  - controller e rota.
- **`Integration\OnboardingRenewalTest`** (MariaDB, banco `*_test`):
  - a prévia sem gravar;
  - extintores (12 meses) e caixa d'água (6 meses) renovados pelo catálogo;
  - as auditorias `update` e `renew`;
  - a situação "Em dia";
  - AVCB sem validade informada desfazendo o lote;
  - AVCB com a validade informada;
  - escopo.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.
- **Sem tela nova neste patch.** A tela do checklist pode ganhar a seleção com "Renovar"; quando ganhar, vem o artigo
  da Central de Ajuda.
