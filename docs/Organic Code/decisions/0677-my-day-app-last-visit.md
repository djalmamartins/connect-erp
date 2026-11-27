# ADR 0677 — Última vistoria aberta pelo Painel da visita

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
O Painel da visita (ADR 0662) mostrava só uma linha: "Última vistoria: modelo · há N
dias · N problemas". Antes de entrar no prédio, quem vai visitar quer ver o que deu
problema da outra vez, com as fotos (ADR 0674).

## Decisão
- A linha da última vistoria vira um botão **Ver** que abre a tela da vistoria
  (`/vistoria/[id]`) com o `last_visit.id` que o `GET /my-day/visit/{id}` já manda.
- Na vistoria concluída ou cancelada, a tela mostra o resumo e os botões
  **Todos** e **Só os problemas** (quando há itens com problema). Nada pode ser
  alterado, porque o servidor responde `editable: false`.
- Nenhuma mudança na API: o `GET /inspections/{id}` já pede a permissão de ver
  vistorias e o condomínio no escopo.

## Consequências
- Testes: `visibleItems` no `npx jest`; `npx tsc --noEmit`.
