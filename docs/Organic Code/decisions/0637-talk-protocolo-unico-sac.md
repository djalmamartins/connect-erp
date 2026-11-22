# 0637 — Protocolo único entre o Talk e o SAC

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0308 (Talk abre, transfere e encerra chamados), 0636 (link de acompanhamento), 0631 (SAC).
- Numeração: faixa avulsa 0600–0799; 0636 é do link de acompanhamento. Conferidos índice e pastas em 22/11.

## Contexto
O Djalma procurou no SAC o protocolo de um atendimento do Talk (26112200403, mostrado como `SAC26112200403`) e não
achou: o chamado, quando existia, tinha outro número (`CH...`), e o do Talk ficava só em `external_ref`, fora da busca.
Ele quer **um protocolo só**, o mesmo nos dois sistemas, e decidiu (cartão de decisão de 22/11/2026) que o chamado só vai
para o SAC quando o atendente clica em **Abrir chamado** no Talk, com título, descrição e o setor que vai cuidar.

## Decisão
- `TicketService::open` aceita, só para a origem `talk`, um `protocol` no formato do Talk (`^\d{11}$`: aammdd + 5
  dígitos). Cabe no `CHAR(12)` e não colide com `CH...` nem `OC...`.
- `TalkHelpdesk::open` usa o `talk_protocol` como protocolo do chamado (tirando o `SAC` dos antigos). Se o número já
  for de outro chamado, fica o `CH...` e o do Talk continua em `external_ref`, sem duplicar.
- A busca de chamados procura também em `external_ref`, então o protocolo do Talk (com ou sem `SAC`) acha o chamado.
- O link de acompanhamento (ADR 0636) e a consulta do widget aceitam os dois formatos.

## Consequências
- Chamados antigos não mudam de número; os novos do Talk têm o número da conversa.
- Quando abrir chamado, encerrar ou não o chamado junto com a conversa é decisão do Talk (ADR 0005 do Talk): o chamado
  aberto pelo botão segue no SAC depois que a conversa termina no WhatsApp.
- Testes: `TalkConversationTest::testTicketUsesTheTalkProtocolAndIsFoundBySearch`.
