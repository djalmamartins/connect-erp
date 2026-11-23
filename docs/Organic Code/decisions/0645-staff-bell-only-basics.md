# 0645 — Sininho da equipe só com o básico (sem erros, auditoria e artigos)

- Status: Aceito
- Data: 23/11/2026
- Pedido: Djalma, 22/11/2026: "revisa toda a parte de notificação, está chegando muita notificação, são 99, está
  chegando erro detectado no Moves ... não precisa chegar, é só o básico mesmo, só o que foi enviado, chamado,
  ocorrência, pequenas coisas ... sem essa parte de erro ... deixa que isso eu vou olhando."
- Relacionadas: ADR 0142 (sino único ERP/Studio/Help Desk), 0258/0300 (logs mais quietos), 0601 (e-mails do
  morador), 0639 (aviso de proposta nova), 0641 (aviso do chamado no app do morador)
- Número: 0643 ficou com o PR #715 (login único do Talk); 0644 foi deixado para a thread das propostas no ERP, aberta
  ao mesmo tempo.

## Contexto

CONFIRMADO no código (main 040dd29): o sino da equipe lê a tabela `notifications`. Gravavam nela 10 origens:

| Origem | Aviso | Quantos |
|---|---|---|
| `TicketService::notifyOpened` | Novo chamado | um por chamado, para a equipe |
| `TicketService` (resposta/situação) | Resposta e mudança de situação do chamado | um por movimentação |
| `Communication::deliver` | Mensagem enviada na Central de notificações do Studio | um por envio |
| `ProposalService` | Proposta nova do site | um por proposta |
| `Studio::notifyAgendaEvent` | Novo compromisso na agenda | um por compromisso |
| `CollectionActionReminders` | Ação de cobrança para hoje / atrasada | um por contato, uma vez |
| `AccountabilityDeadline` | Prestação de contas vence em breve / atrasada | um por condomínio e mês |
| `OnboardingAlerts` | Item do checklist de chegada vencendo / vencido | um por item e situação |
| `AppLogger::notifyIncident` | **Erro detectado no MovesOS** (+ e-mail) | um por incidente novo de erro |
| `Audit::record` | **Alteração no sistema** | um por registro alterado por qualquer pessoa |
| `Studio` (blog) | **Novo artigo: ...** | um para cada usuário a cada artigo novo |

As três últimas são monitoramento, não trabalho, e eram as que enchiam o sino.

## Decisão

1. Ficam no sino só os avisos de trabalho (as oito primeiras linhas). A regra está escrita em
   `Source\Support\StaffNotificationPolicy`.
2. `AppLogger` continua gravando cada incidente em `app_log` (Studio › Logs do sistema), mas não grava mais aviso
   "Erro detectado no MovesOS" nem coloca o e-mail desse aviso na fila.
3. `Audit::record` continua gravando tudo em `system_audit_logs` (Studio › Auditoria), sem o aviso "Alteração no
   sistema".
4. Artigo novo do blog não manda mais aviso para toda a equipe.
5. Migration `20261123_0010_quiet_staff_notifications` (não apaga nada): marca como lidos os avisos antigos desses três
   tipos e cancela os e-mails de incidente que ainda estavam pendentes na fila. A reversão não desfaz nada.

## Consequências

- O contador do sino passa a contar só chamado, ocorrência, proposta, mensagem enviada, agenda e prazos do dia.
- Erros precisam ser acompanhados em Studio › Logs do sistema (e no vigia da hospedagem por e-mail, ADR 0333, que não
  mudou).
- Aviso novo para a equipe deve entrar na lista de `StaffNotificationPolicy`; monitoramento não vai ao sino.

## Testes

- Unitário `StaffNotificationPolicyTest`: os três tipos saem, os de trabalho ficam, e as três origens não gravam mais.
- Integração `QuietSystemLogsTest`: alteração feita por outra pessoa fica na auditoria sem aviso; erro novo fica em
  `app_log` sem aviso e sem e-mail.
