# 0631 — SAC: Help Desk passa a se chamar SAC e o balão mostra quem está online

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0034/0305 (todo chamado num lugar só), 0355 (widget do atendimento), 0141 (Central de Ajuda),
  0604 (LGPD/CDC no site), 0630 (Meu Dia, onde fica a autorização).

## Contexto
Pedido do Djalma (20/11/2026), depois do estudo "Meu Dia, vistorias e SAC": trocar o nome Help Desk por SAC em tudo que
a pessoa vê, mantendo o funcionamento, e um balão de atendimento que mostra quem da equipe está online, com WhatsApp,
abrir chamado, acompanhar protocolo e Central de Ajuda; fora do horário, ninguém aparece. Ele pediu cinza-claro com
dourado no lugar do azul.

## Decisão
1. **Nome**: "Help Desk" vira "SAC" nos textos de tela, e-mails, artigos e guias. Endereços (/helpdesk), tabelas
   (`studio_support_*`), slugs de permissão e classes continuam iguais: só o nome muda, nada quebra.
2. **Presença** (`SacDesk::touch`): quem tem `support.manage` e abre uma tela do ERP (Panel::layoutData) ou do SAC
   (Studio::tickets e agenda) grava `users.sac_seen_at`, no máximo uma vez por minuto por sessão. Falha é silenciosa.
3. **Online** = `sac_public = 1`, presença nos últimos 5 minutos, nível de equipe (≥ 5), no máximo 3 pessoas.
4. **Consentimento (LGPD)**: aparecer no site depende de autorização da própria pessoa, no Meu Dia ("Balão do SAC no
   site"), com POST + CSRF e registro no histórico (`sac_public_at` + Audit). Sem autorizar, a pessoa atende normalmente.
   O site recebe só primeiro nome, inicial e foto (miniatura): nunca e-mail, sobrenome ou id.
5. **Horário**: dias úteis sem feriado nacional (`AccountabilityDeadline::isBusinessDay`), 8h às 18h por padrão,
   ajustável por `CONNECT_SAC_HOURS=HH:MM-HH:MM` no .env. Fora do horário, ninguém aparece e a janela avisa que o chamado é
   respondido no próximo dia útil.
6. **Balão**: `/suporte/widget` (cinza-claro, borda e ícones dourados, botão do WhatsApp verde para contraste e
   reconhecimento) e o botão `helpdesk-widget.js` com a pílula "Precisa de ajuda?" + rostos de quem está online, lida de
   `GET /suporte/widget/equipe` (JSON público, sem cookie, cache de 60 s, CORS aberto porque só leva dados autorizados).

## Consequências
- Migration `20261120_2300_sac_widget` (colunas `sac_seen_at`, `sac_public`, `sac_public_at` e índice em `users`), com `.down.sql`.
- Ninguém aparece no balão até autorizar: no primeiro dia o balão mostra "Atendimento aberto" sem rostos.
- O WhatsApp sai do número das configurações do site (Studio › Configurações); vazio, o botão não aparece.
- Testes: `SacDeskTest` (integração: consentimento, 5 minutos, horário, feriado, dados expostos, limite por sessão) e
  `SacDeskRulesTest` (unidade: horário, texto e o balão).
- Fica para a v1.1: o app da equipe com notificação e o WhatsApp organizando tarefas (estudo de 20/11).
