# ADR 0679 — Repetir em outro dia no app Meu Dia

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
Rondas, conferências e lembretes se repetem, mas para marcar de novo era preciso
digitar tudo na aba Nova.

## Decisão
- O menu do compromisso (`eventActions`, ADR 0671) ganha **Repetir em outro dia**,
  em qualquer situação: em aberto, concluído ou cancelado.
- A aba Nova abre preenchida pelos parâmetros de `copyParams` (`src/lib/day.ts`):
  - mesmo título, tipo, descrição e horário;
  - data do dia seguinte ao compromisso, ou de amanhã se ele já passou.
- O chamado não vai junto: a cópia é uma tarefa nova, salva pelo
  `POST /my-day/events` de sempre, com as mesmas regras e a mesma auditoria.
- A Nova confere os parâmetros antes de usar: o tipo precisa ser conhecido, a data
  precisa ser AAAA-MM-DD e o horário HH:MM.
- Nenhuma mudança na API.

## Consequências
- Testes: `copyParams` no `npx jest` (dia seguinte, compromisso passado, sem fim);
  `npx tsc --noEmit`.
