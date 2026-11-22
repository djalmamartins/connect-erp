# 0636 — Protocolo dos chamados no formato do Connect Talk: SAC e OCO + data + sequência do dia

- Status: Aceito
- Data: 22/11/2026
- Pedido: Djalma, 22/11/2026: "SAC202611160008 ... fazer com que o sistema do SAC funcione do mesmo jeito, os números
  de protocolo ... as ocorrências do mesmo jeito, mas com as letras de ocorrência. Quando for chamado, qualquer coisa,
  é o SAC."
- Relacionadas: ADR 0034 (porta única de chamados), 0305 (toda ocorrência é chamado), 0308/0634 (API do Talk),
  0631 (SAC e balão)

## Contexto

CONFIRMADO no código (main f677636): `TicketService::open` gerava `CH` + aammdd + 4 hexadecimais aleatórios
(ex. CH261109A1B2, coluna `char(12)`). As ocorrências antigas migradas pelo ADR 0305 ganharam `OC` + id com 10 dígitos.
O Connect Talk numera as conversas como `SAC` + AAAAMMDD + sequência do dia com 4 dígitos (ex. SAC202611160008).
A consulta pública de protocolo (`/suporte/widget`) só aceitava o formato `CH`.

## Decisão

1. **Formato.** Chamado (SAC, Help Desk, ERP, site, API, Talk, pedido de "chamado" no app): `SAC` + AAAAMMDD +
   sequência do dia. Ocorrência (a que nasce com o chamado, ADR 0305, pelo ERP, pela API ou pelo app): `OCO` +
   AAAAMMDD + sequência do dia. Cada prefixo tem a sua sequência, que recomeça em 0001 todo dia; passou de 9999 no
   dia, segue com 5 dígitos. Regra em `Source\Services\Support\TicketProtocol`.
2. **O tipo vem do código, não da entrada.** `TicketService::open` recebe o tipo (`KIND_TICKET` por padrão);
   só `Occurrences` pede `KIND_OCCURRENCE`. No app do morador, "Chamado" passa `KIND_TICKET` até o
   `RegistryService` (o chamado continua nascendo com a ocorrência, como no ADR 0305). Formulário e API não escolhem.
3. **Sequência sem repetição.** Tabela `studio_support_protocol_sequences (prefix, day, last_number)`: o número é
   tirado dentro da transação do chamado (`INSERT ... ON DUPLICATE KEY UPDATE` + `SELECT ... FOR UPDATE`). A linha
   fica travada até o commit, então dois chamados ao mesmo tempo não pegam o mesmo número, e um chamado desfeito
   devolve o número. Número já usado é pulado; o índice único do protocolo continua de guarda.
4. **Mesmo número do Talk.** Chamado aberto pelo Talk com `talk_protocol` no formato SAC, data válida e ainda livre
   fica com esse número como protocolo: o morador vê um número só. Outro formato (ex. o antigo `20261115-00001`)
   recebe o próximo SAC do dia. INFERIDO/risco: como o Talk e o ERP numeram cada um do seu lado, uma conversa do
   Talk pode ter o mesmo número de um chamado do ERP que não veio dela. Solução definitiva (PENDENTE, repo do Talk):
   o Talk usar o `protocol` que o POST /talk/tickets devolve.
5. **Antigos ficam.** Os protocolos `CH...` e `OC...` não são renumerados: já foram para e-mails, WhatsApp e papel.
   A busca do ERP, do SAC e a consulta pública aceitam os quatro formatos, digitados com espaço, traço, ponto ou
   minúsculas ("sac 2026-1116-0008").
6. **Coluna.** `protocol` passa de `char(12)` para `varchar(20)`.

## Onde aparece

Sem mudança de tela: o número já aparece na lista e no detalhe do ERP e do SAC, no app web e no app nativo do
morador ("Nº ..."), no assunto dos e-mails (`[SAC2026...] ...`), nas notificações, no widget do site e nas respostas
da API (`protocol` em /tickets e /talk/tickets, `number` em /resident/tickets). O exemplo do campo do widget e a
documentação OpenAPI passaram para o formato novo.

## Migration

`20261122_1200_ticket_protocols` (idempotente): amplia a coluna e cria a tabela da sequência. A reversão apaga a
tabela e mantém a coluna com 20 caracteres (voltar a `char(12)` cortaria os protocolos novos).

## Testes

- Unit `TicketProtocolTest`: formato, 5 dígitos, normalização da busca, tipo pelo prefixo.
- Integração `TicketProtocolSequenceTest`: SAC e OCO com sequências próprias, novo dia em 0001, número devolvido no
  rollback, número usado pulado, protocolo do Talk aproveitado (e recusado com data inválida ou formato antigo),
  busca por protocolo novo e antigo. Ajustados: TicketIntake, ServiceDesk, TalkIntegration, ResidentAppIntegration.

## Riscos e lacunas

- Mesmo número em lados diferentes (item 4) até o Talk passar a usar o protocolo devolvido pelo ERP.
- A sequência depende da hora do servidor (fuso de `date_default_timezone`), como o resto do sistema.
