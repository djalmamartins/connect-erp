# ADR 0673 — Semana sem internet no app Meu Dia

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
A ADR 0669 guardou no aparelho a última resposta do `GET /my-day` para a aba Hoje
funcionar sem internet. A aba Semana chama o mesmo endpoint (a resposta já traz
`week` e `week_events`), mas sem conexão mostrava só o erro.

## Decisão
- O carregamento do dia com a cópia sem internet sai da aba Hoje para o hook
  `src/lib/useDay.ts`, usado por Hoje e Semana. As duas abas usam a mesma chave
  (pessoa e data), então a semana vista em Hoje aparece na Semana sem conexão e
  vice-versa.
- O aviso amarelo vira o componente `src/ui/OfflineBanner.tsx`.
- `isOfflineError` (em `src/lib/offline.ts`) decide quando usar a cópia: só sem
  rede ou sem resposta. Erro do servidor (403, 500) continua aparecendo como erro.
- Regras da ADR 0669 mantidas: só a pessoa conectada, 7 entradas, apagado ao sair.
  Nenhuma mudança na API.

## Consequências
- Hoje e Semana não divergem no comportamento sem internet.
- Testes: `npx tsc --noEmit` e `npx jest` no app (teste novo de `isOfflineError`).
