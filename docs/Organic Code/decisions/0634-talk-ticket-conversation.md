# 0634 — Conversa dos chamados do Connect Talk nos dois sentidos

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0308 (Talk abre, transfere e encerra chamados), 0623 (token com escopos), 0142 (conversa do
  chamado e resposta de quem pediu), 0305 (ocorrência é chamado), 0632 (consultas por morador).
- Numeração: faixa avulsa 0600–0799; 0633 é do adaptador do Sicoob (outra thread). Conferidos índice e branches em 22/11.

## Contexto
Etapa 4 (última) da API do ERP para o Connect Talk. Até aqui a conversa do WhatsApp virava chamado, mas a resposta da
equipe no Help Desk ficava presa no ERP: o morador não recebia, e o que ele escrevia depois no WhatsApp não entrava no
chamado. O Talk do Raspberry Pi fica atrás de um túnel e o ERP não chama o Talk; o caminho é o Talk perguntar.

## Decisão
1. **`GET /talk/tickets?after=N`**: as respostas da equipe nos chamados de origem talk do escopo, com cursor pelo id da
   mensagem (`meta.next_after`), 100 por pedido (`has_more`). Sem cursor, só as últimas 24 horas. Saem só mensagens
   públicas: nota interna nunca. Não saem as escritas pelo próprio usuário de integração (o que veio do WhatsApp o Talk
   já tem). Da equipe vai só o primeiro nome; anexo vai como contagem (o arquivo fica no ERP, com login).
2. **`GET /talk/tickets/{id}`**: o chamado com a conversa pública; `from_talk` marca o que veio do WhatsApp.
3. **`POST /talk/tickets/{id}/messages {message, talk_message_id?}`**: a mensagem do WhatsApp entra como resposta de quem
   pediu, pelo `TicketService::replyAsRequester` (mesma regra do ERP e do app: aguardando o cliente volta para
   atendimento, resolvido ou fechado reabre, a equipe é avisada). Texto puro. Idempotente pelo `talk_message_id`
   (evento `talk_message` no chamado): repetir devolve a primeira, com `duplicate: true`.
4. **Escopo `tickets.write`** para as três: quem abre e encerra o chamado é quem acompanha a conversa. Sem escopo novo
   e sem migration; o token que já existe (escopos legados) passa a ver as respostas.
5. Chamado que não é do Talk, de condomínio fora do escopo ou que não existe = 404. Chamado sem condomínio só se foi
   aberto pelo próprio usuário de integração.

## Consequências
- O Talk faz polling (por exemplo a cada 30 s) e manda as respostas no WhatsApp; o que o morador responde volta ao
  chamado. Nada é enviado do ERP para fora.
- Fecha as 4 etapas da proposta. Ações em nome do morador (reservar, avisar visita) seguem à espera da decisão do Djalma.

## Testes
`tests/legacy/Unit/TalkConversationRoutesTest.php` e `tests/legacy/Integration/TalkConversationTest.php` (feed sem nota
interna nem a própria mensagem, cursor, primeiro nome, reabrir o resolvido, sem duplicar, sem HTML, 404 fora do Talk ou
do escopo, 403 sem o escopo).
