# ADR 0671 — Ações do compromisso na Semana do app Meu Dia

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
Na aba Semana do app Meu Dia os compromissos apareciam só como texto, sem as
cores por tipo nem o vermelho das atrasadas que a aba Hoje ganhou (ADR 0665), e
para concluir ou adiar (ADR 0668) era preciso abrir o dia em Hoje primeiro.

## Decisão
- As cores dos compromissos saem de um único mapa (`src/ui/tones.ts`), usado por
  Hoje e Semana: borda esquerda por tipo, verde quando feito, vermelho quando
  atrasado (com o ícone de alerta na Semana).
- O menu do compromisso (concluir, cancelar, reabrir, adiar com atalhos ou
  outro dia e hora) passa para `src/lib/eventActions.ts` e é chamado pelas duas
  abas; depois da ação, a aba que chamou recarrega.
- Nenhuma mudança na API: usa `POST /my-day/events/{id}/status` e
  `POST /my-day/events/{id}/move`, com as mesmas regras de permissão e auditoria.

## Consequências
- Um só lugar para mudar o menu e as cores; Hoje e Semana não divergem.
- Testes: `npx tsc --noEmit` e `npx jest` no app; sem teste de integração novo
  porque o servidor não mudou.
