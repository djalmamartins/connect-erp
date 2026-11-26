# 0660 — App do SAC no celular, login da equipe por token e push dos apps da equipe

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 19:30: app de celular (iPhone e Android) do Meu Dia e do SAC, separados mas
  interligados, tudo pela API REST do ERP. Esta ADR traz a base comum dos dois apps (login e push) e o app do SAC;
  o app do Meu Dia está na ADR 0659 e as tarefas atrasadas no Meu Dia na ADR 0658. (Nasceu 0657, renumerada porque a
  0657 entrou na main com o login do morador.)

## Contexto

- A API da equipe (`/api/v1`, middleware `ApiAuthenticated`) só aceitava a sessão do navegador com CSRF. Um app
  nativo não tem cookie de sessão.
- O app do morador já tem sessão por token Bearer (ADR 0303): login com a mesma senha e o mesmo limite do site,
  2FA por desafio de uso único, token de 256 bits guardado só como sha256, 90 dias, 30 dias sem uso, logout global.
- O push do morador (ADR 0642) usa o Expo e uma fila enviada pelo cron.

## Decisão

- **Mesma regra, outro público.** As tabelas `erp_resident_app_tokens` e `erp_resident_app_challenges` ganham a
  coluna `audience` ('resident' para todas as linhas existentes, 'team' para a equipe). `ResidentAppSessions`
  recebe o público no construtor e filtra por ele em todas as consultas: token do morador não entra na API da
  equipe e o da equipe não entra no app do morador. `Source\Services\Team\TeamAppSessions::make()` monta a versão
  da equipe: só entra quem tem `erp.access`; 2FA obrigatório e não configurado = 409 (configurar pela web).
- **Rotas:** `POST /api/v1/team/app-sessions` (+ `/verify`, `DELETE /current`), públicas como as do morador;
  `GET /api/v1/team/me` (nome, papel, `can_see_team` = `erp.myday.team`, `can_attend_sac` = `support.manage`);
  `POST`/`DELETE /api/v1/team/push-devices` {token, app: `sac`|`meu-dia`, platform} (DELETE também por query).
- **Bearer no `ApiAuthenticated`:** com "Authorization: Bearer" o middleware autentica o token da equipe
  (`Auth::actingAs`, sem cookie e sem CSRF, que não se aplica a quem não usa cookie). Token presente e inválido =
  401, sem cair na sessão. Cada rota continua com as próprias permissões e o escopo de condomínio, então o app
  vê exatamente o que a pessoa vê no ERP.
- **Push:** `team_push_devices` (um aparelho por token e app, até 5 por pessoa e app) e `team_push_queue`.
  `TeamPush::send(userIds, app, título, texto, data)` só enfileira para quem tem aquele app e nunca lança;
  `tools/team-push-dispatch.php` (cron de minuto) envia. `TEAM_PUSH_DELIVERY=expo` envia pelo Expo; o padrão (`log`)
  não envia nada e marca "skipped". `usersWithDevices(app)` serve aos lembretes do Meu Dia.
- Migration `20261126_2300_team_app` (reversível: o down apaga só as sessões da equipe).

### Atendimento do SAC pela API

- `Api\V1\SacDesk`: `GET /sac/summary` (meus em aberto, meus atrasados, sem responsável, atrasados, hoje),
  `GET /sac/options` (situações, prioridades, equipes; responsáveis e respostas rápidas só para quem atende),
  `GET /sac/tickets?view=mine|unassigned|overdue|today|all` (cada chamado com `late`), `GET /sac/tickets/{id}`
  (conversa com notas internas e histórico para quem atende), `POST /sac/tickets/{id}/take` (assumir),
  `/triage` e `/answers`. As mesmas regras da tela `/operacional/chamados`: `erp.access` e escopo de condomínio para
  ver, `support.manage` para atender; o trabalho é do `TicketService`/`TicketDesk` que a tela já usa.
- Push no app do SAC: chamado novo (equipe que já recebe o aviso hoje), mensagem de quem pediu (responsável, ou a
  equipe se não houver) e chamado passado para outra pessoa. Quem fez a ação não recebe.
- `GET /ticket-attachments/{id}` aceita também o token da equipe (`AppAttachmentApiAuthenticated`).

### App `apps/sac` (Expo, React Native, mesmo design do app do morador)

- Login com a conta do ERP (2FA quando ativo). Aba **Fila**: Meus, Sem responsável, Atrasados e Todos, busca por
  assunto ou protocolo, atrasado em vermelho e no topo, faixa vermelha com o total de atrasados, cartão
  "Meu Dia de hoje" (dados de `GET /my-day`) que abre o app Meu Dia (`connectmeudia://`) ou a página do Meu Dia.
  **Chamado**: dados, ligar/e-mail para quem pediu, Assumir, Situação, Prioridade, Passar para, conversa com notas
  internas em amarelo, resposta ou nota interna, resposta rápida, "Enviar e resolver". **Perfil**: sair do aparelho.
- O toque no push abre o chamado; o número no ícone é meus em aberto + sem responsável.
- Esquema `connectsac://chamados/{id}` para o Meu Dia abrir um chamado.

## Segurança e LGPD

- Só o sha256 do token e do desafio fica no banco; nada vai para log ou auditoria. Login e saída auditados
  (`team_app.login`, `team_app.logout`).
- O push leva só título e uma frase curta (sem dado financeiro nem documento) e o caminho da tela.

## Consequências

- Qualquer rota da API da equipe passa a valer no celular sem mudança. O Meu Dia (`/api/v1/my-day`) já funciona.
- Teste no celular pelo Expo Go: `bash tools/app-sac/celular.sh` no Mac (ponte só para as rotas da equipe).
- Pendente: publicação nas lojas (contas Apple e Google da Connect), projectId do EAS para o push e
  `TEAM_PUSH_DELIVERY=expo` em produção; anexar foto pelo app do SAC.
