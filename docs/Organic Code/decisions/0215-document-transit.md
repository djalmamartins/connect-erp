<!-- markdownlint-disable MD024 -->
# 0215 — Documentos em trânsito: malotes e avisos de recebimento parados

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0050 (documentos operacionais), 0207 (mesma conta de dias dos atrasos), 0214 (conferência dos
  cheques)

## Contexto

Os cadastros de malotes (AP-129) e de avisos de recebimento (AP-126) registram o envio, o recebimento e a conferência.
Nada aponta o que está parado:

- o malote enviado que ninguém recebeu;
- o malote recebido que ninguém conferiu;
- o AR que nunca voltou.

O AR de uma notificação de multa é o que conta o prazo de defesa do morador. Um AR esquecido em trânsito trava a
cobrança da multa. Fora do próprio cadastro, nada no `source/` usa essas tabelas.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/document-transit?condominium_id=&as_of=&days=`. O service é
`DocumentTransit`.

### Regra pura `stage()`

| Registro | Situação | Etapa | Contado desde |
|---|---|---|---|
| Malote | enviado, sem recebimento | `awaiting_receipt` | o envio |
| Malote | recebido, sem conferência | `awaiting_check` | o recebimento |
| AR | em trânsito | `in_transit` | o envio |

Malote conferido e AR entregue ou devolvido ficam de fora.

### Reuso

- **Dias em aberto:** a mesma conta dos atrasos (`Agreements::daysLate`).
- **Atrasado:** mais dias que `days` (padrão 7, de 1 a 90).
- **Baixa:** continua pelo formulário de cada cadastro.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo, obrigatório; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access`, `erp.pouches.view` e `erp.delivery_notices.view`; sem uma delas, 403
  (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** id, etapa, datas, dias, a quantidade de documentos do malote e a unidade do AR.
- **Dados que não saem:** portador, conteúdo, destinatário, assunto e código de rastreio. Eles levam nome e, pelo
  rastreio, o endereço de uma pessoa.
- **Limites:** até 2000 registros em aberto (acima disso, 422) e 500 itens, os mais antigos primeiro (`truncated`).
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\DocumentTransitTest`:**
  - a regra pura;
  - a montagem no SQLite: etapas, dias, limite, envio futuro e outro condomínio de fora, e nenhum dado pessoal no JSON;
  - validação e escopo (404);
  - contrato: somente leitura, sem as colunas pessoais;
  - controller e rota.
- **`Integration\DocumentTransitTest`** (MariaDB, banco `*_test`):
  - malotes e ARs criados pelo formulário;
  - etapas, dias e atrasados;
  - limite de dias;
  - sem dado pessoal;
  - nenhuma linha nova na auditoria;
  - escopo.
- **Sem tela nova neste patch.** O painel operacional pode ganhar o cartão "Documentos parados"; quando ganhar, vem o
  artigo da Central de Ajuda.
