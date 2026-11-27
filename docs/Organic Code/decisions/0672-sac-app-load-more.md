# 0672 — "Carregar mais" na Fila e no Buscar do app do SAC

- Status: Aceito
- Data: 27/11/2026
- Pedido: ciclos de melhoria a cada 30 minutos pedidos pelo Djalma (26/11 22:57). Continua as ADRs 0660 e 0670.

## Contexto

A fila do app trazia no máximo 100 chamados, sem jeito de ver os mais antigos. Nas abas Meus e Sem responsável,
os resolvidos eram tirados depois da consulta, então a lista podia vir com menos itens do que o limite.

## Decisão

- **`GET /api/v1/sac/tickets`** aceita `before` (id do último chamado recebido) e devolve `meta.next`: o id para
  pedir a página seguinte, ou `null` quando acabou. A ordem continua a do Help Desk (mais novo primeiro, empate pelo
  id), e a página seguinte segue essa mesma ordem.
- `TicketService::listFor` ganha os filtros `open` (só em aberto, direto na consulta) e `before`. As abas Meus e Sem
  responsável usam `open`, então a página vem cheia.
- **App:** 50 chamados por página. No fim da Fila e do Buscar aparece **Carregar mais** enquanto houver página
  seguinte; a página nova se junta sem repetir chamado. Puxar para atualizar volta para a primeira página.
- Sem tabela nova, sem migration. As telas do ERP e o `/tickets` do morador não mudam.

## Testes

- `SacDeskIntegrationTest::testLoadMorePagesInOrderAndOpenOnlyInTheQueue` (três páginas na ordem, empate no mesmo
  segundo, `next` nulo no fim, aba Sem responsável sem o resolvido e com a página cheia).
- `apps/sac/__tests__/tickets.test.ts` (`appendPage`) e `sac-api.test.ts` (`limit` e `before` na URL).
