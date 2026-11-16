# 0323 — Connect Talk dentro do ERP: container/talk, login e Help Desk do próprio ERP

- **Status:** aceito (fase 1 de 3)
- **Data:** 2026-11-16
- **Relacionados:** ADR-002 (administradora única), 0034 (alternador de ambientes), 0141 (Central de Ajuda),
  0308 (integração do Talk pela API, que continua valendo até a virada). Repositório `djalmamartins/talk` 0.22.0, que
  passa a ser só referência.
- **Numeração:** faixa avulsa (0322 é da Cora).

## Contexto

Em 16/11/2026 o Djalma pediu o Talk "dentro do ERP, como um aplicativo lá dentro do sistema", em `container/talk`.
Entre as opções (Talk separado com seletor e login único, ou o Talk inteiro dentro do ERP) ele escolheu mover tudo:
mesmo login, mesmos usuários e permissões, e o chamado direto no Help Desk, sem API no meio. O Talk separado
(PHP sem framework + MariaDB) já tinha caixa de conversas, setores, Jack, campanhas, agendamentos, relatórios e
equipe, com 151 testes.

## Decisão

- **Onde fica.** Telas, CSS e JS em `container/talk/connect/v1/` (front controller `app.php`, `views/`, `assets/`).
  Código em `source/Services/Talk/` (namespace `Source\Services\Talk\…`, o antigo `ConnectTalk\…`). O Talk responde
  no host `talk.<domínio>` (`talk_host()`); o `index.php` do ERP entrega o pedido ao `app.php` logo depois de abrir a
  sessão, exceto `/ambiente/*` (troca de ambiente).
- **Alternador.** Novo ambiente `talk` em `Environments` (ícone de conversa, "Atendimento por WhatsApp"), com a
  permissão nova `talk.access` (migration 20261116_1200, dada de início aos papéis que já têm `support.manage`).
  A ida do ERP para o Talk usa o código de uso único de sempre (0034).
- **Login.** O Talk não tem mais login nem senha. Entra quem está logado no ERP, ativo e com `talk.access`
  (`ErpLogin`). Sem sessão, vai para o login do ERP. O que é do Talk continua no Talk: o papel (atendente, gestor,
  supervisor) e os setores ficam em `talk_users`/`talk_staff`, ligados ao usuário do ERP por `talk_users.erp_user_id`
  (primeiro pelo id, depois pelo mesmo e-mail, para reaproveitar a equipe do Talk separado). Perfil novo: o primeiro
  vira supervisor, os demais entram sem setor até o supervisor escolher em Equipe. Perfil desativado em Equipe não entra.
- **Banco.** As 24 tabelas `talk_*` entram nas migrations do ERP (20261116_1300, `CREATE TABLE IF NOT EXISTS`, igual
  ao esquema das migrations 001–019 do Talk). O Talk usa o mesmo banco numa conexão própria (`TalkApp::db()`), porque
  o código dele conta com `FETCH_ASSOC` e prepares nativos.
- **Cadastro e chamados direto.** `LocalContactDirectory` e `LocalHelpdesk` usam o `TalkHelpdesk` de 0308 sem HTTP:
  mesma busca do morador pelo telefone e mesmo chamado de origem `talk`, idempotente pelo protocolo. A identificação
  roda ao chegar a mensagem, antes de haver atendente, então vale para todos os condomínios ativos (uma
  administradora só, ADR-002). Transferir e encerrar o chamado são feitos em nome do usuário do ERP do atendente.
- **Configuração.** Chaves `TALK_*` e `EVOLUTION_*` no `.env` do ERP (lista em `Config.php`). Sem `TALK_KEY` o Talk
  abre; só o que guarda credenciais de canal avisa que falta a chave.
- **Fases.** 1: base (este ADR). 2: atendimento completo com WhatsApp (worker, webhooks e mídia no host talk). 3: Jack,
  campanhas, agendamentos, relatórios, equipe com usuários do ERP e a virada, com cópia dos dados do Talk separado.
  Até a virada, o Talk separado continua usando a API de 0308.

## Consequências

- Um sistema só para instalar, atualizar, fazer backup e auditar; o morador e o chamado são os mesmos do ERP.
- A rota `/api/v1/talk` e o token `ctk_` de 0308 ficam até a virada e saem depois, em outra decisão.
- Riscos: o Talk passa a cair junto com o ERP; o worker de envio precisa rodar no servidor do ERP (fase 2); a
  identificação global vê moradores de todos os condomínios (aceito por ADR-002).
- Testes: os 147 testes do Talk rodam na suíte do ERP (`tests/legacy/{Unit,Integration}/Talk`), mais
  `ErpBridgeTest` para login, cadastro e Help Desk.
