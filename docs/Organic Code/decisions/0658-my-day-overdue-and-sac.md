# 0658 — Meu Dia: o que ficou para trás vai para hoje, em vermelho, e o SAC sem responsável

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 (por voz): "dá uma olhada se está interligado o SAC com o Meu Dia ... quando tiver algum
  chamado, alguma coisa para atender, ele cai no Meu Dia ... ontem, dia 25, ficou uma tarefa para trás, ele devia ficar
  em vermelho e essa tarefa ser jogada para o próximo dia para ser cumprida. E quando ela fechar, volta ao normal. Para a
  gente nunca esquecer nada e ficar os alertas ... tudo pela API REST".
- Relacionadas: ADR 0630 (Meu Dia), 0631 (SAC), 0637 (protocolo SAC/OCO), 0645 (sininho só com o básico), 0601 (e-mails
  do morador). Numeração combinada com as threads abertas no mesmo dia: 0657 login Bearer da equipe e push, 0659 app Meu
  Dia, 0660 ações do SAC e app do SAC.

## Contexto (CONFIRMADO na main aeb45b9)

- O SAC já entrava no Meu Dia: chamado **atribuído** à pessoa aparecia em "Para agendar" ou "Aguardando cliente"; ao
  agendar, virava compromisso. Chamado **sem responsável** não aparecia no Meu Dia de ninguém.
- Compromisso de um dia anterior que não foi concluído ficava só no dia dele: abrindo hoje, não aparecia nada.
- O cartão "Atrasados" contava só chamados para agendar com prazo vencido e vistorias atrasadas. Não havia alerta.

## Decisão

1. **Ficou para trás** (`MyDay::overdue()` e o campo `overdue` de `MyDay::day()`): compromisso/tarefa com dia anterior a
   hoje e situação `scheduled` (até `OVERDUE_DAYS` = 30 dias para trás), cujo chamado ligado não está resolvido/fechado;
   chamado atribuído com prazo vencido em aberto ou em atendimento (aguardando cliente não conta); vistoria atrasada;
   item do checklist vencido. Ordem do mais antigo para o mais novo. O chamado agendado num compromisso atrasado aparece
   uma vez (pelo compromisso) e o chamado da vistoria atrasada também (pela vistoria).
2. **Nada muda de data.** O item fica no dia original em vermelho (`events[].overdue`, `strip[]/week[].overdue`) e é
   mostrado de novo hoje, no quadro vermelho "Ficou para trás" do alto da agenda, com Concluir/Cancelar para tarefas.
   Concluir, cancelar, resolver o chamado, fazer a vistoria ou regularizar o item tira do quadro: volta ao normal.
3. **SAC sem responsável** (`unassigned`): chamados abertos ou em atendimento sem ninguém atribuído, no escopo de
   condomínios, só para quem tem `support.manage`. Não contam como atraso da pessoa.
4. **Alertas.** Selo vermelho no item Meu Dia do menu lateral (conta guardada na sessão por 2 minutos e limpa ao
   marcar/concluir). `MyDayAlerts::run()` às 7h (`tools/my-day-alerts.php`, scheduler do lab e `cron.sh dia`): um aviso
   "Tarefas atrasadas no Meu Dia" por pessoa por dia no sininho; o aviso anterior não lido é marcado como lido. Só
   sininho da equipe (entra na lista de `StaffNotificationPolicy`); nenhum e-mail para morador.
5. **API REST junto** (tag MyDay): `GET /api/v1/my-day` ganha `overdue`, `unassigned`, `summary.overdue_oldest`,
   `summary.unassigned`, `events[].overdue`, `week[]/strip[].overdue` e `carried`; novo `GET /api/v1/my-day/overdue`
   (`count`, `oldest`, `items`). `summary.overdue` passa a contar a lista inteira. Os apps de iPhone/Android do Meu Dia
   e do SAC (ADRs 0659/0660) usam esses campos; o login por token da equipe é da ADR 0657.
6. Sem migration: tudo sai das tabelas que já existem.

## Consequências

- Compromisso antigo (mais de 30 dias) que nunca foi concluído não volta para hoje; continua no dia dele.
- Quem tinha compromissos antigos da agenda do Studio sem concluir vai ver os dos últimos 30 dias no quadro na primeira
  vez; basta concluir ou cancelar.
- Os campos antigos da resposta não mudaram de nome nem de forma.

## Testes

- Integração `MyDayTest`: atrasadas levadas para hoje (ordem, dias de atraso, um chamado só, 30 dias, aguardando
  cliente fora), vermelho no dia original, volta ao normal ao concluir e ao resolver o chamado; SAC sem responsável só
  para quem atende e no escopo; alerta uma vez por dia.
- `OpenApiRoutesTest`, `tests/erp/render-smoke.php`, `php tools/help.php check`.
