# 0665 — App Meu Dia: progresso do dia e compromissos com cor por tipo

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "continuar desenvolvendo e melhorando o sistema a cada 30 min" (ciclo 3 do app Meu Dia).
  O topo com o progresso e os cartões com borda colorida estavam na prévia aprovada ("o resto está ok").
- Depende de: ADR 0659 (app Meu Dia) e ADR 0658 (atrasadas).

## Decisão

- Em **Hoje** (só no dia de hoje), um quadro verde-escuro com borda dourada mostra "N de M feitos" (cancelados não
  contam), uma barra dourada, as atrasadas em vermelho-claro e o próximo compromisso pelo horário ("Próximo: 14:00 ·
  Vistoria"). Sem compromisso e sem atrasada, o quadro não aparece.
- Os compromissos da agenda viram cartões com a hora à esquerda e borda esquerda colorida: verde (feito, riscado),
  vermelho (atrasado), azul (reunião), verde (tarefa), amarelo (prazo) e dourado (atendimento do SAC).
- Regras puras `dayProgress` e `eventTone` em `apps/meu-dia/src/lib/day.ts`, com testes em `__tests__/progress.test.ts`.
- Só no app: nenhuma mudança na API, no banco ou no ERP.
