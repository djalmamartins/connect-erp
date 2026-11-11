<!-- markdownlint-disable MD024 -->
# 0216 — Baixa em lote de malotes e avisos de recebimento

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0050 (documentos operacionais), 0215 (documentos em trânsito), 0213 (mesmo padrão: regravar
  pelo formulário, tudo ou nada e prévia)

## Contexto

O relatório de documentos em trânsito (ADR 0215) aponta os malotes e os ARs em aberto, mas a baixa é feita registro por
registro no formulário. O caso comum é em lote:

- o portador entrega vários malotes de uma vez;
- os Correios devolvem um maço de ARs no mesmo dia.

Hoje, cada um vira uma edição separada, com a mesma data digitada várias vezes.

## Decisão

Rota nova: `POST /api/v1/document-transit/settle`, com `{condominium_id, on, pouch_ids?, notice_ids?, check?, outcome?,
dry_run?}`. O service é `DocumentTransitSettlement`.

### Reuso

- Cada documento é regravado pelo `RegistryService::save` do cadastro (`pouches` ou `delivery_notices`).
- Valem as regras do formulário: a data vem depois do envio e o recebimento antes da conferência.
- Vale também a auditoria `update` do formulário.
- Nenhuma escrita própria no banco.

### Regra pura `settlement()`

| Documento | Situação | Vira |
|---|---|---|
| Malote | enviado | recebido em `on`; com `check`, também conferido em `on` |
| Malote | recebido | conferido em `on` |
| AR | em trânsito | entregue em `on` (`outcome` = `delivered`, o padrão) ou devolvido (`returned`) |

Malote conferido e AR entregue ou devolvido são recusados.

### Gravação

- **Tudo ou nada:** a gravação é feita numa transação com os documentos travados (`FOR UPDATE`).
- **Recusa antes de gravar:** documento de outro condomínio, apagado ou já resolvido recusa o lote inteiro. O 422 traz
  `errors` com a chave `tipo:id`.
- **Recusa pelo formulário:** um documento recusado desfaz todos. O 422 traz os erros e `_pouch` ou `_notice`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** um registro `transit_settle` por documento (situação antes e depois, e a data), só quando grava.
- **Nada é enviado.**
- **Validação:** `on` não pode ser futura; de 1 a 200 documentos por vez.

### Acesso

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access` e, para cada lista preenchida, ver e gerenciar o cadastro (`erp.pouches.view` e
  `erp.pouches.manage`; `erp.delivery_notices.view` e `erp.delivery_notices.manage`); sem uma delas, 403
  (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

### Dados

Saem o tipo, o id e as situações antes e depois. Não saem portador, conteúdo, destinatário, assunto nem código de
rastreio.

## Consequências

- **`Unit\DocumentTransitSettlementTest`:**
  - a regra pura;
  - validação e escopo (404);
  - o lote recusado antes de gravar (já resolvido, de outro condomínio, inexistente), sem transação aberta;
  - contrato: só pelo formulário;
  - controller e rota.
- **`Integration\DocumentTransitSettlementTest`** (MariaDB, banco `*_test`):
  - a prévia sem gravar;
  - a baixa de malote enviado, de malote recebido e de AR, com datas e auditoria;
  - AR devolvido sem data de entrega;
  - o relatório da ADR 0215 refletindo a baixa;
  - recebimento antes do envio recusado pelo formulário, sem gravar nada;
  - escopo.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.
- **Sem tela nova neste patch.** O relatório de documentos parados pode ganhar a seleção com "Dar baixa"; quando
  ganhar, vem o artigo da Central de Ajuda.
