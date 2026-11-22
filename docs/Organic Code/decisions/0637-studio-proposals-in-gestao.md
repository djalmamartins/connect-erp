# 0637 — Propostas do site em Studio > Gestão, com aviso no sininho

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0604 (LGPD e CDC no site, aceite no pedido de proposta), 0621 (visual do Moves OS no Studio),
  0388 (mídia das propostas fora de storage/), 0141 (Central de Ajuda).
- Numeração: faixa avulsa 0600–0799; 0635 é da base única de relatório e 0636 da rolagem do menu lateral.
  Conferidos índice e branches em 22/11.

## Contexto
O Djalma pediu o item **Propostas** dentro de **Gestão** no menu do Moves Studio, um aviso a cada proposta nova e a
confirmação de que o formulário do site (`/solicite-sua-proposta`) chega nessa tela.

Conferência (CONFIRMADO no código):
- O formulário do site manda nome, e-mail, WhatsApp, condomínio, unidades, função, mensagem, a ciência da Política de
  Privacidade (obrigatória) e novidades (opcional, desmarcada), com CSRF, honeypot e limite de 4 envios por hora.
- `ProposalService::submit` grava na tabela `proposals` com protocolo, os aceites em `privacy_consents` (ADR 0604),
  manda o e-mail de recebimento e cria o aviso; a tela `/studio/proposals` lista a tabela `proposals`. Os dois já
  estavam ligados.
- O sininho do Studio consulta o número de avisos a cada 50 s e toca `assets/audio/notification.mp3` quando sobe.

Falhas encontradas:
1. O item estava no grupo **Visão geral**.
2. O aviso saía só se a categoria `studio` de `notifications_categories` existisse. Ela vem de uma migration antiga
   (`legacy-migrations/20260815_notifications_audit.sql`) e não está na estrutura base da instalação limpa
   (ADR 0379/0396): na produção nova a proposta gravava, mas ninguém era avisado, sem erro.
3. Quem recebia o aviso era uma lista fixa de papéis (`developer`, `super_admin`, `client_admin`, `manager`), e não
   quem tem a permissão `proposals.manage`. Um papel novo com a permissão não era avisado; um `manager` sem ela era.

## Decisão
1. **Propostas** sai de Visão geral e entra como primeiro item de **Gestão** no menu do Studio. Rota e permissão
   (`proposals.manage`) não mudam.
2. O aviso vai para quem `Access::can('proposals.manage')` — a mesma regra que abre a tela, com papel e exceções do
   usuário (`ProposalService::proposalTeam`).
3. Se a categoria `studio` não existir, o serviço cria (`Studio`, tipo `system`); se não conseguir, registra no log
   (`proposal_notify_failed`). Sem migration: uma linha de cadastro, criada na primeira proposta.
4. Nada muda nos dados coletados nem no aceite: o formulário continua pedindo só o necessário para responder
   (política de privacidade, item "Pedido de proposta no site"; guarda até 2 anos sem contrato).

## Consequências
- A cada proposta, a equipe comercial vê o número do sininho subir, ouve o som e o aviso abre a proposta.
  Também recebe o e-mail interno, como antes.
- Testes: `StudioProposalsMenuTest` (unidade: menu, som, campos do formulário x serviço) e
  `ProposalNotificationIntegrationTest` (integração: grava, cria a categoria uma vez e avisa só quem tem a permissão).
- Artigo na Central de Ajuda: `configuracoes/propostas-do-site.md`.
