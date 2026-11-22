# 0641 — Morador avisado no app a cada movimentação do chamado, e equipe do chamado como lista

- Status: Aceito
- Data: 22/11/2026
- Pedido: Djalma, 22/11/2026: "quando é aberto um chamado, deveria notificar o app ... toda vez que tivesse alguma
  mudança ... o atendente fez qualquer movimentação, notifica no app para a pessoa poder acompanhar ... onde tem
  escrito equipe, que está exemplo manutenção, deveria ser uma listazinha: administrativo, financeiro, manutenção,
  geral, para poder selecionar e salvar."
- Relacionadas: ADR 0142 (aviso a quem pediu), 0302/0303 (apps do morador), 0305 (ocorrência é chamado),
  0307 (TicketDesk), 0601 (morador recebe só 5 e-mails), 0622 (tela de chamados do ERP), 0637 (protocolo SAC/OCO)

## Contexto

CONFIRMADO no código (main c532afc):
- `TicketService::notifyRequester` só gravava a notificação do sino do ERP/Studio (tabela `notifications`) na resposta
  e em 3 situações (aguardando, resolvido, fechado). O sininho do app do morador (`ResidentFeed::notifications`) não
  lia essa tabela, então o morador não via nada do chamado no app.
- `TicketService::notifyOpened` mandava ao morador que pediu o e-mail "Novo chamado" com o link do SAC, contra a
  ADR 0601.
- Não havia push no app nativo (`apps/morador` não tinha `expo-notifications`).
- A equipe do chamado (`studio_support_tickets.team`) era texto livre no ERP e no SAC.

## Decisão

1. **Aviso por movimentação.** `Source\Services\Resident\ResidentTicketNotices::record` grava um aviso em
   `resident_ticket_notices` para quem pediu o chamado (`requester_id`), em:
   abertura (inclusive quando o próprio morador abriu: confirma o protocolo), resposta ao cliente, troca de situação,
   de equipe e de responsável (só o primeiro nome de quem atende). Várias mudanças salvas juntas viram um aviso só.
   Ganchos: `TicketService::open`, `TicketDesk::update` e `TicketDesk::reply`, o serviço único do ERP, do SAC, da API e
   do Talk. Prioridade, categoria e tags são triagem interna e não avisam.
2. **Nota interna nunca avisa.** Só a troca de situação que a nota causa (aberto → em atendimento) vira aviso, sem o
   texto da nota.
3. **Sem e-mail.** O aviso vai para o sininho e o push. O e-mail "Novo chamado" deixa de ir para quem pediu sem acesso
   ao ERP ou ao SAC (ADR 0601). A equipe continua recebendo como antes.
4. **Sininho.** `ResidentFeed::notifications($link, $today, $userId)` junta os avisos dos últimos 30 dias (até 20) dos
   chamados que a pessoa pediu no condomínio do vínculo escolhido. `kind: ticket`, `path: /ocorrencias/{id}` (app web);
   o app nativo troca para `/chamados/{id}`. Vale para o app web (`www.connect.lab/app`) e o app nativo.
5. **Push.** `Source\Services\Resident\ResidentPush`: o app registra o token do Expo (`POST /api/v1/resident/push-token`)
   depois do login e tira ao sair (`POST /api/v1/resident/push-token/forget`). O cron de minuto
   (`tools/resident-push-dispatch.php`, chamado pelo `tools/hosting/cron.sh minuto`) envia os avisos pendentes.
   `CONNECT_RESIDENT_PUSH=expo` envia pelo serviço de push do Expo (`EXPO_ACCESS_TOKEN` opcional); o padrão (`log`) não
   chama nada fora e marca o aviso como `skipped`. `DeviceNotRegistered` apaga o token. Até 10 aparelhos por conta.
   O texto do push é o título e uma frase curta, sem valor nem documento.
6. **Equipe como lista.** `TicketService::TEAMS = Administrativo, Financeiro, Manutenção, Geral` (guardada pelo nome).
   ERP e SAC trocam o campo de texto por uma lista com "Sem equipe". `TicketDesk::update` recusa nome fora da lista
   (aceita maiúscula e sem acento). Texto antigo fora da lista continua até alguém escolher outra equipe; a lista mostra
   "(antiga)" ao lado dele. A migration acerta a grafia dos textos que já batiam com a lista.

## Migration

`20261122_1500_resident_ticket_notices` (idempotente): tabelas `resident_ticket_notices` e `resident_push_tokens`
(com FK para o chamado e para o usuário, apagando junto) e a grafia das equipes. A reversão apaga as duas tabelas;
a grafia das equipes fica.

## Testes

- Unit `ResidentTicketNoticesTest`: textos dos avisos (abertura, situação + equipe + responsável num aviso só,
  resposta com resolução, ocorrência no feminino), lista de equipes, token e mensagem do push.
- Integração `ResidentTicketNoticesIntegrationTest`: sequência completa (abertura, nota interna, triagem, resposta,
  resolução) com 5 avisos, nada da nota interna, nenhum e-mail ao morador, sininho só no condomínio do vínculo e só
  para quem pediu; equipe fora da lista recusada e texto antigo preservado; push no modo log e expo com transporte
  falso, token não registrado apagado.
- App nativo (jest): rota do aviso `/ocorrencias/{id}` → `/chamados/{id}` e toque no push.

## Riscos e lacunas

- PENDENTE (Djalma): o push no celular só funciona num build do app (EAS) com o `projectId` em
  `app.json › extra.eas.projectId`, as credenciais da Apple (APNs) e do Google (FCM), e `CONNECT_RESIDENT_PUSH=expo` no
  `.env` da produção. Sem isso o app não pede a permissão e o aviso fica só no sininho. O Expo Go não recebe push no
  Android desde o SDK 53.
- O push depende do cron de minuto. Sem cron, o aviso só aparece no sininho.
- Os prints da Central de Ajuda da tela do chamado não mostram a triagem; não foram regerados.
