# 0635 — CND só por pedido à administradora e nunca com débito em aberto

- Status: Aceito
- Data: 22/11/2026
- Pedido: Djalma, 22/11/2026: "Certidão de débito, ele não poderia ser criado se tiver débito. Não cria. [...] Só
  informa que tem débito e não gera o certificado. E aí, em nenhuma das instâncias, nem morador, nem nada." Em
  seguida: "qualquer coisa que o usuário puder emitir, tirando o boleto, [...] deveria ser feita uma solicitação à
  administradora. E aí a administradora recebe no ERP, gera e envia para a pessoa e aparece para ela no aplicativo."
- Relacionadas: ADR 0044 (documentos da unidade), 0105 (situação na data de referência), 0302 (app do morador),
  0305 (toda ocorrência e pedido do morador é chamado do Help Desk)

## Contexto

Até aqui a Certidão de Débitos (AP-123) **sempre** emitia: "nada consta" sem conta vencida, e uma certidão "com
débitos" listando as contas quando havia. O app do morador (web em /cnd e celular em `apps/morador/app/cnd.tsx`)
deixava o próprio morador emitir a do dia, pela mesma regra (`UnitDocuments::issueForResident`). Um documento
"certidão" gerado com débito pode ser alterado, mal lido ou repassado sem conferência, e era emitido sem ninguém da
administradora olhar.

Levantamento do que o morador emite sozinho no app (rotas POST do web e da API /resident): só a CND gera documento.
Boleto fica de fora por decisão do Djalma. O resto (reservas, visitantes, chamados, moradores da unidade, foto,
privacidade) não gera documento.

## Decisão

1. **Trava única no servidor.** `UnitDocuments::issueFor` (o único lugar que grava `erp_unit_documents`) recusa
   `debt_certificate` quando há conta vencida em aberto **na data de referência ou hoje**, com
   `DebtCertificateBlocked` (um `DomainException`): a mensagem só informa quantas contas e o valor atualizado. A data
   antiga não serve de atalho. A recusa é auditada (`refuse` em `erp_unit_documents`). Vale para a tela do ERP, a
   API `POST /api/v1/unit-documents` (409 `debt_open`) e o lote de exemplo do lab.
2. **O morador não emite: pede.** `issueForResident` foi removido. `POST /cnd` (web) e
   `POST /api/v1/resident/certificates` (celular, agora 202) chamam `ResidentCertificates::request`: com débito
   vencido hoje, recusa (409 `debt_open`, nada é criado, recusa auditada); sem débito, abre um chamado do Help Desk
   (origem `residents`, área financeira, categoria **Pedido de CND**, unidade e condomínio do vínculo, solicitante o
   morador). Pedido aberto da unidade é devolvido em vez de duplicar (`created = false`).
3. **A administradora emite e o app mostra.** A equipe emite em Documentos › Certidão de Débitos. Depois de gravar
   uma certidão "nada consta", `ResidentCertificates::fulfil` responde (mensagem pública com número e código de
   verificação) e resolve os pedidos abertos da unidade; o morador é avisado pelo sino, como em qualquer chamado
   (ADR 0601: sem e-mail). Se essa resposta falhar, a certidão fica gravada e o erro vai para o log; a equipe
   responde à mão.
4. **No app só certidão negativa.** `GET /resident/certificates`, a tela /cnd e o PDF mostram só certidões
   `result = clear`. Novo `GET /api/v1/resident/certificates/status` (e a mesma situação no web) diz se há débito
   (então a tela mostra só o débito e "Ver boletos", sem botão de pedido) e qual o pedido aberto.
5. As certidões "com débitos" emitidas antes desta decisão continuam no histórico do ERP e na verificação pública
   (são verdadeiras para a data delas), mas não aparecem nem baixam no app.
6. O pedido aparece na lista de chamados do morador como **chamado** (não ocorrência).

## Consequências

- Nenhum caminho do sistema gera certidão de débitos com conta vencida em aberto.
- Muda o contrato da API do morador: POST devolve o pedido (202) em vez da certidão (201). O app do celular foi
  atualizado no mesmo PR; versão antiga instalada mostra o protocolo como erro de leitura, sem gerar nada.
- A CND do morador passa a depender do atendimento da equipe (prazo do chamado: 72 h, prioridade média).
- Não há trava no banco (CHECK) porque já existem linhas antigas "com débitos"; a trava é a do serviço, coberta por
  testes de integração.

## Testes

- `UnitDocumentsIntegrationTest`: recusa com débito (com encargos), na data antiga da dívida e com data antiga sem
  débito quando hoje há débito; nada é gravado.
- `ResidentAppIntegrationTest::testResidentRequestsCndAndTeamIssuesOnlyWithoutDebt`: pedido vira chamado, não
  duplica, a emissão no ERP resolve o chamado e a certidão aparece; com débito o pedido e o ERP recusam, recusas
  auditadas; certidão antiga "com débitos" não lista nem baixa; vizinho não vê.
- `UnitDocumentsTest` (mensagem) e `apps/morador/__tests__/client.test.ts` (202, 409 `debt_open`, status).
