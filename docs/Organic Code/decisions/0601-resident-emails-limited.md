# 0601 — Morador recebe só cinco e-mails

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0054/0055 (outbox e fila), 0056 (boleto por e-mail), 0059 (comunicados), 0090 (convite), 0142 (Help Desk), 0301 (prestação de contas), 0304 (2FA do morador), 0397 (layout dos e-mails)

## Contexto
Pedido do Djalma em 20/11/2026: o morador não pode receber "spam toda hora". Levantamento da main 0c0f4f4: o morador
recebia por e-mail lista de espera, fatura emitida, boleto emitido, acordo criado, acordo quebrado, reserva aprovada,
comunicado, aviso de cobrança manual, convite, prestação de contas, resposta e mudança de situação do chamado e a
mudança da verificação em duas etapas.

## Decisão
1. O morador recebe por e-mail só:
   1. o boleto **no dia do vencimento**, avisando que vence hoje (`charge.due_today`, modelo `charge-due-today`);
   2. a **prestação de contas do mês**, entre os dias 5 e 10: fechada antes do dia 5, fica agendada para o dia 5 às 8h;
      do dia 5 em diante sai na hora (depois do dia 10 sai atrasada, para o morador não ficar sem);
   3. **aviso de atraso com 10, 15, 30, 45 e 60 dias** (`charge.overdue`, modelo `charge-overdue`);
   4. o **convite de cadastro** (ADR 0090);
   5. a **recuperação de senha**, quando ele pede (`Auth::forget`, fora da outbox).
2. Regra única em `Source\Services\Erp\ResidentEmailPolicy`. `NotificationOutbox::record()` grava os outros tipos já
   cancelados (histórico e relatórios continuam, com o motivo `DISABLED_REASON`), e o `NotificationDispatcher` cancela
   qualquer aviso antigo de outro tipo que ainda estivesse pendente na fila. Nenhum deles vira e-mail.
3. `ResidentChargeNotices` (cron diário `tools/resident-charge-emails.php`, no grupo `dia` do `tools/hosting/cron.sh`
   e no `service/jobs/scheduler.sh`): "boleto" é o título do banco pendente ou registrado ou, sem título ativo, a conta a
   receber da unidade (inclui as contas dentro do boleto único, ADR 0331). Um e-mail por unidade por tipo e dia.
4. Sem envio duplicado: tabela `erp_resident_charge_notices` com chave única (boleto, marco). Rodar de novo no mesmo
   dia ou com dois processos não repete. Pago ou cancelado deixa de ser avisado. Se o cron falhar, o aviso de atraso
   ainda sai até 3 dias depois do marco (e antes do marco seguinte); o "vence hoje" só sai no próprio dia.
5. Quem recebe os avisos de boleto continua sendo só quem autorizou cobrança por e-mail no cadastro
   (`billing_by_email`, ADR 0056); a prestação de contas vai a quem não recusou comunicado (`notices_opt_out`).
6. Desligados para o morador: comunicado (continua no app e no portal), aviso de cobrança manual (a tela e a API
   recusam com mensagem), e-mail do chamado para quem não usa ERP nem Help Desk (fica o sino e o app; o visitante do
   site sem conta continua recebendo a resposta, porque não tem outro canal) e e-mail da verificação em duas etapas
   do app do morador.
7. Fora do escopo: e-mails da equipe (Help Desk, agenda, comunicação interna, vistorias, checklist de chegada, 2FA do
   ERP/Studio) e as propostas do site.

## Consequências
- Migration `20261120_2000_resident_charge_notices` (reversível). Precisa rodar no lab e na produção.
- O cron diário precisa do `tools/resident-charge-emails.php` (já no `cron.sh dia`).
- O relatório "Boletos enviados" (AP-099) mostra só o histórico antigo de `bank_charge.issued`.
- O descadastro continua pela nota de cada e-mail (fale com a administração) e pelo `unsubscribe_url` da moldura
  quando o fluxo com token existir (thread de LGPD).
- Testes: `ResidentEmailPolicyTest` (unit) e `ResidentChargeNoticesIntegrationTest` (integração).
