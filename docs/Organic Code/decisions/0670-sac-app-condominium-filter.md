# 0670 — Filtro por condomínio na Fila do app do SAC

- Status: Aceito
- Data: 27/11/2026
- Pedido: ciclos de melhoria a cada 30 minutos pedidos pelo Djalma (26/11 22:57). Continua as ADRs 0660, 0661,
  0664 e 0666.

## Decisão

- **App:** quem atende mais de um condomínio vê, acima das abas da Fila, uma fileira de botões com "Todos os
  condomínios" e cada condomínio do seu escopo (`GET /sac/options`). A escolha fica guardada no aparelho
  (SecureStore) e volta para "Todos" se o condomínio sair do escopo. Com um só condomínio, a fileira não aparece.
- **Fila:** `GET /api/v1/sac/tickets` já aceitava `condominium_id` (ADR 0660); o app passa a enviar.
- **Números:** `GET /api/v1/sac/summary` aceita `condominium_id`. Os números das abas e o aviso de atrasados ficam
  só daquele condomínio. Condomínio fora do escopo devolve 403, como `/sac/units`.
- O número no ícone do app continua sendo da fila inteira: com um condomínio escolhido, o app não muda o número.
- Sem tabela nova, sem migration.

## Testes

- `SacDeskIntegrationTest::testCondominiumFilterOnQueueAndNumbers` (números gerais e por condomínio, fila filtrada,
  403 fora do escopo).
- `apps/sac/__tests__/tickets.test.ts` (`queueCondominium`) e `sac-api.test.ts` (parâmetro só quando escolhido).
