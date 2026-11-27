# 0668 — Adiar compromisso do Meu Dia (API e app)

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "continuar desenvolvendo e melhorando o sistema a cada 30 min" (ciclo 5 do app Meu Dia).
  No pedido original: "tarefa para trás fica em vermelho e é jogada para o próximo dia até fechar"; o app agora deixa a
  pessoa remarcar o compromisso em vez de só concluir ou cancelar.
- Depende de: ADR 0630 (Meu Dia), ADR 0658 (atrasadas) e ADR 0659 (app Meu Dia).

## Decisão

- **API** `POST /api/v1/my-day/events/{id}/move` com `date` (AAAA-MM-DD), `start` (HH:MM) e `end` opcional
  (`MyDay::moveEvent`). Sem `end`, mantém a duração que o compromisso tinha (sem fim continua sem fim). Só para
  compromisso em aberto (`scheduled`; concluído ou cancelado devolve 422 em `status`). Quem pode mexer é o mesmo do
  `/status`: responsável, quem criou ou quem tem `erp.myday.team` (senão 404). Grava na auditoria o horário antigo e o
  novo. POST como o `/status`, aceito com sessão + CSRF ou com o token Bearer dos apps da equipe.
- **App**: no toque do compromisso, a opção **Adiar ou mudar o horário** oferece +1 hora (se couber no dia), amanhã e
  a próxima segunda no mesmo horário; atrasado ganha "Hoje às HH:MM". **Outro dia e hora** abre `app/adiar/[id].tsx`.
  Ao recarregar Hoje, os lembretes do aparelho são refeitos com o novo horário.
- O push "marcou uma tarefa para você" não repete ao adiar (o registro em `my_day_push_log` é por compromisso).
- Sem migration.

## Testes

- Integração `MyDayTest::testMoveEventKeepsDurationAndRules` (duração mantida, fim informado, 404 para outra pessoa,
  422 de validação e de compromisso concluído, auditoria).
- App: `postponeOptions` e `addMinutes` em `__tests__/progress.test.ts`.
