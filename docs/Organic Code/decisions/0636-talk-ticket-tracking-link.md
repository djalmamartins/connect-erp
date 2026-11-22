# 0636 — Link público para acompanhar o chamado aberto pelo WhatsApp

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0308 (Talk abre, transfere e encerra chamados), 0634 (conversa dos chamados do Talk), 0631 (SAC),
  0141 (Central de Ajuda), 0365 (telas internas fora da busca). No Talk: ADR 0004 (Jack), revisão de 22/11/2026.
- Numeração: faixa avulsa 0600–0799; 0635 é da base única de relatório. Conferidos índice, pastas e PRs abertos em 22/11.

## Contexto
Pedido do Djalma (22/11/2026): quando a Jack, a assistente do Connect Talk, abre o chamado, ela deve dizer ao cliente
o número e onde ele acompanha o andamento, e responder a situação quando o cliente manda o número no WhatsApp.
O SAC já tinha o acompanhamento do widget do site, mas por protocolo + e-mail; quem escreve pelo WhatsApp não tem e-mail
no chamado. Todo chamado sem conta (`requester_id` nulo), como os do Talk, já nasce com `access_token` (64 hexadecimais,
`random_bytes(32)`), pensado para o solicitante de fora acompanhar.

## Decisão
1. **Página pública `GET /suporte/chamado/{protocolo}?c={código}`** na Central de Ajuda, sem login. O código é o
   `access_token` do chamado, comparado com `hash_equals`. Código errado, protocolo errado ou chamado sem código = a mesma
   página "Chamado não encontrado" (404), sem dizer o motivo. Limite de 30 aberturas a cada 15 minutos (`request_limit`).
2. **Mínimo necessário (LGPD):** assunto, situação, área, data de abertura e da última atualização e as respostas
   públicas. A descrição da abertura (que no Talk leva telefone, condomínio e a conversa), as notas internas, os anexos
   (só a contagem) e o nome completo da equipe (só o primeiro nome) não saem. O que veio do WhatsApp aparece como "Você";
   o texto de encerramento que o Talk deixa (`TalkHelpdesk::CLOSED_TEXT`) aparece como "Equipe Connect".
3. **`noindex`, `Referrer-Policy: no-referrer` e `Cache-Control: private, no-store`:** o código vai na URL, então a página
   não entra na busca, não vaza o endereço para outro site e não fica em cache compartilhado.
4. **API do Talk:** o resumo do chamado (`POST /talk/tickets` e `GET /talk/tickets/{id}`) ganha `tracking_url` para os
   chamados de origem talk. O Talk guarda o link e a Jack manda junto com o número.
5. Serviço `Source\Services\Support\TicketTracking` com a regra; o controlador só aplica cabeçalhos, limite e a tela.
   Sem migration: `access_token` já existe com índice único.

## Consequências
- O cliente acompanha o chamado sem conta e sem e-mail; para responder continua usando o WhatsApp.
- Quem recebe o link encaminhado vê aquele chamado (só o mínimo acima). A Central de Ajuda avisa para não encaminhar.
- Não há expiração do link; se for preciso, um chamado pode ganhar código novo trocando o `access_token` (fora deste ADR).
- Testes: `TalkConversationTest::testTrackingLinkShowsStatusAndPublicRepliesOnly` e
  `ServiceDeskTest::testPublicTicketPageNeedsTheAccessCode`.
