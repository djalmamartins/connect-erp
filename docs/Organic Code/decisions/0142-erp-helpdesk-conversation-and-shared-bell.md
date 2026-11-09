# 0142 — Integração ERP, Help Desk e Studio: conversa do chamado, sino compartilhado e links entre ambientes

Data: 2026-11-09 · Status: aceito (pedido do Djalma; decisões tomadas sem ele, por delegação) · CN-004, ADR 0034

## Contexto

O Djalma testou a main e disse que "ainda está faltando integração, tanto com o Help Desk como com o CMS" e que
"os chamados não foram abertos". O ADR 0034 já levava todo chamado ao Help Desk pelo `TicketService` e ligava os
ambientes pelo alternador da sidebar. No teste local (MariaDB, ERP e Help Desk no navegador) a abertura pelo ERP
funcionou; o que faltava era o resto do caminho:

- o ERP só abria e listava o chamado; quem pediu não via a resposta do Help Desk nem conseguia responder;
- a resposta do Help Desk não avisava ninguém (só a abertura avisava a equipe);
- o sino do ERP mostrava só a lista de demonstração, não as notificações reais que o Studio e o Help Desk gravam;
- os links entre hosts (erp.* ↔ studio.*) iam direto ao outro host, sem a sessão de lá, e o Studio recusava
  links de notificação para o host do ERP;
- "Chamados" ficava escondido em Operacional › Rotina. Pelo relato, é provável que o Djalma não tenha achado a
  tela (ele também não achou Ocorrências, Reservas e Manutenções, que ficam no mesmo submenu).

## Decisão

1. **Chamado no ERP com conversa.** `GET /operacional/chamados/{id}` mostra o pedido, as respostas da equipe e as
   do solicitante, situação, prazo, condomínio, unidade e responsável. As notas internas do Help Desk nunca
   aparecem no ERP. `POST /operacional/chamados/{id}/responder` (CSRF, limite 30 em 5 min) grava a mensagem de quem
   pediu: "aguardando cliente" volta para "em atendimento" e resolvido ou fechado é reaberto. A visibilidade é a
   mesma da lista (escopo de condomínio ou ser quem abriu ou pediu); fora disso é 404.
   API junto: `GET` e `POST /api/v1/tickets/{id}/messages`, documentados no openapi.yaml.
2. **Avisos nos dois sentidos** (`TicketService::notifyRequester` e `notifyTeam`). Resposta ao cliente no Help
   Desk (não a nota interna) e mudança para "aguardando cliente", "resolvido" ou "fechado" avisam quem pediu e quem
   abriu, no sino e por e-mail, com o link do chamado no ERP para quem entra no ERP (ou no Help Desk para quem só
   atende). Solicitante sem conta recebe só o e-mail, sem link de sistema. A resposta pelo ERP avisa o responsável,
   ou a equipe do Help Desk inteira se ainda não houver responsável. O autor nunca é avisado de si mesmo. Falha no
   aviso é registrada e não desfaz a resposta.
3. **Sino compartilhado.** O sino do ERP lê a mesma tabela `notifications` do Studio (`NotificationFeed`): contador
   de não lidas, as 8 mais recentes e "Marcar todas como lidas". O clique passa por `GET /notificacoes/{id}`, que
   só abre notificação da própria pessoa, marca como lida e segue o link. Sem banco (smoke de renderização) o sino
   volta para a demonstração.
4. **Links entre ambientes com a sessão.** O alternador ganhou deep link: `/ambiente/{ambiente}?ir=/caminho`
   leva o caminho pela troca com código de uso único (ADR 0034). `Environments::safePath()` só aceita caminho local
   dentro da área do ambiente (Help Desk em /helpdesk, Studio em /studio, ERP qualquer caminho do host dele), sem
   "//", barra invertida, caracteres de controle ou /ambiente. `Environments::link()` vai direto no mesmo host e
   pela troca entre hosts. `Environments::resolveLink()` aceita link de notificação do próprio host ou de um host
   irmão (erp.*, studio.*, www.* do mesmo domínio) e recusa o resto; o Studio passou a usá-lo.
5. **Pontes visíveis.** Barra superior do ERP com o menu "Suporte" (Abrir chamado, Meus chamados e, para quem tem
   `support.manage`, Help Desk). No Help Desk, o chamado tem "Ver no ERP" (para quem tem `erp.access`). No ERP, o
   chamado tem "Atender no Help Desk" (para quem atende). O chamado da ocorrência (AP-112) passa a abrir no ERP.

Sem tabela nova e sem migration.

## Consequências e lacunas

- Não deu para ler o log de erro do Mac: o `app_log` fica no banco. Se o chamado ainda não abrir no Mac, o
  `ticket_create_failed` no `app_log` (Studio › Logs) diz o motivo.
- O sino do ERP também mostra os alertas de erro que o Studio já mandava para desenvolvedores (nível 10). É o mesmo
  comportamento do sino do Studio.
- Responder com anexo pelo ERP e acompanhamento do solicitante sem conta pelo código de acesso ficam para depois.
- A troca entre hosts continua por clique; sair num host não encerra a sessão no outro.
- Integração com o CMS (comunicados, FAQ e central de ajuda no ERP) não entrou aqui; a central de ajuda está com a
  thread "Criar central de ajuda com tutoriais ilustrados" (ADR 0141).
