# 0044 — Documentos da unidade: certidão, declaração, quitação e anuência

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

No passo 7, a seção Documentos do catálogo tem quatro documentos ligados à situação financeira de uma
unidade:
- Certidão de Débitos (AP-123);
- Declaração de Inadimplência (AP-124);
- Comprovante de Quitação (AP-125);
- Carta de Anuência (AP-130).

As contas a receber por unidade (ADR 0033 e 0039) e os encargos (ADR 0040) já existem. As fichas
ainda são scaffold; as regras abaixo são PROPOSTAS.

## Decisão

- **Gerados das contas a receber válidas da unidade, na data de referência** (até hoje), sem
  digitação:
  - **Certidão de Débitos:** sempre emite. Sem conta vencida em aberto, sai "nada consta"
    (`result clear`). Com conta vencida, lista as vencidas com correção, multa e juros pela regra do
    condomínio e as a vencer em aberto (`result debts`).
  - **Declaração de Inadimplência:** só com conta vencida em aberto (senão 409).
  - **Comprovante de Quitação:** até a competência informada, todas as contas precisam estar pagas
    ou canceladas (senão 409, com a quantidade em aberto). Mostra quantas foram pagas e o total.
  - **Carta de Anuência:** só sem conta vencida em aberto, com a finalidade registrada (ex.: venda da
    unidade).
- **Foto do conteúdo:** o que foi apurado fica gravado em `erp_unit_documents.payload_json`
  (migration 0526). Emitido não muda, mesmo que as contas mudem depois; cancelar exige motivo e
  fica auditado.
- **Código de verificação** de 16 caracteres sem ambiguidade (sem 0, O, 1, I ou L), único, mostrado
  em grupos (`ABCD-EFGH-JKMN-PQRS`). `GET /api/v1/unit-documents/verify/{code}` confere a
  autenticidade, aceita o código com ou sem hífens e mostra se o documento foi cancelado. Por ora,
  a verificação exige sessão e respeita o escopo; uma verificação pública fica para decisão própria,
  por expor dados da unidade.
- **Permissões** `erp.unit_documents.view` e `erp.unit_documents.manage`. Escopo por condomínio (404
  fora dele).
- **API:** `GET/POST /api/v1/unit-documents`, `GET /api/v1/unit-documents/{id}`,
  `GET /api/v1/unit-documents/verify/{code}` e `POST /api/v1/unit-documents/{id}/cancel`.

## Consequências

- A frente de layout monta as telas AP-123, AP-124, AP-125 e AP-130 (escolher a unidade, emitir e
  imprimir com o código) sobre a API. O PDF fica para a mesma camada de impressão dos relatórios.
- Os demais itens da seção Documentos ficam para os próximos ciclos: Disco Virtual (AP-122, com
  upload), Avisos de Recebimento (AP-126), Cópias de Cheques (AP-127), Empenhos (AP-128) e Malotes
  (AP-129).
