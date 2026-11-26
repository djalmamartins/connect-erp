# 0643 — Login único do Talk com o usuário do ERP (talk.access por usuário)

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0034 (alternador de ambientes e código de uso único), 0308 (API `/api/v1/talk`, token `ctk_`),
  0359 (Talk separado, no Raspberry Pi), 0623 (escopos do token), 0324 (permissão `talk.access`), 0006 (acesso fail-closed).

## Contexto
Pedido do Djalma (22/11/2026) para a 1.0 do Talk em produção: "os mesmos usuários do ERP consigam acessar e outras
pessoas não consigam acessar o Talk... só quem está cadastrado no ERP como funcionário da Connect... coloca isso para
cada usuário a permissão de acesso lá". O Talk roda no Raspberry Pi (ADR 0359), em outro host, e até aqui tinha
usuários e senhas próprios.

A permissão `talk.access` já existe (migration 20261116_1200) e já pode ser dada por papel ou liberada/negada por usuário
no cadastro (overrides). O alternador `/ambiente/talk` já emitia o código de uso único (ADR 0034), mas o destino era
`talk.<domínio>` e, sem sessão, mandava para o Talk, que mandaria de volta.

## Decisão
1. **O Talk não tem login próprio em produção.** O botão "Entrar pelo Connect ERP" leva a `ERP/ambiente/talk`.
   Sem sessão, o ERP guarda na sessão só a chave `talk` (`Environments::rememberReturn`) e abre o login do ERP
   (senha + 2FA). Depois do login (ou do 2FA em `/studio/login/2fa?next=erp`), `Environments::takeReturn()` volta
   uma vez para `/ambiente/talk`. Nenhum caminho vindo da requisição é usado como destino.
2. **Onde está o Talk.** `TALK_URL` no `.env` do ERP (só `http`/`https`, sem usuário, caminho ou consulta) define host e
   esquema do destino do alternador; sem ele vale `talk.<domínio>` como antes.
3. **Troca do código.** Com sessão completa e `talk.access`, o ERP emite o código (60 s, uma vez, só para `talk`) e manda
   o navegador a `TALK_URL/ambiente/entrar/talk/{código}`. O Talk chama `POST /api/v1/talk/staff/login {code}` com o seu
   token e recebe `{id, name, email}`. Recusa (código vencido, usado, de outro ambiente, conta excluída ou sem
   `talk.access`) = 404 genérico, registrada no log de autenticação; sucesso vai para a auditoria. Limite de 30 por minuto por IP.
4. **Revalidação.** `GET /api/v1/talk/staff/{id}` devolve `active`. O Talk pergunta a cada poucos minutos e encerra a
   sessão quando o acesso foi retirado no ERP. Se o ERP estiver fora do ar, a sessão continua (o atendimento não para).
5. **Escopo novo `staff.login`** (leitura). Não entra em `LEGACY_SCOPES`: o token atual do Pi não ganha login sem alguém
   criar um token novo com o escopo marcado na tela Integrações.
6. **`talk.` passa a ser prefixo estrito** em `Access::STRICT_PREFIXES`: usuário sem papel não entra no Talk pelo nível
   legado (≥ 5). Só papel com `talk.access` ou liberação no cadastro do usuário.

## Consequências
- Quem sai da Connect perde o Talk ao perder o acesso no ERP (no próximo ciclo de revalidação, minutos).
- O Talk guarda o `erp_user_id` do funcionário; papel (supervisor/atendente) e setores continuam no Talk.
- O token do Pi precisa ser recriado com `staff.login`. Sem ele, o Talk mostra que o login pelo ERP não está liberado.

## Testes
`tests/legacy/Integration/TalkStaffLoginTest.php`: uso único, recusas (sem permissão, negado por usuário, sem papel,
outro ambiente, malformado), liberação por usuário, revalidação, rotas e escopo, volta depois do login e `TALK_URL`.

## Revisão de 26/11/2026
Pedido do Djalma: "o ERP cria uma parte de configuração para a gente poder fazer a ligação... todas as informações têm que
vir lá do ERP... essa equipe aqui tem que pegar as informações lá do ERP".
- **`GET /api/v1/talk/staff`** (escopo `staff.login`): quem tem `talk.access` e conta não excluída (`TalkStaffLogin::staff`).
  A tela Equipe do Talk cria quem é novo, atualiza nome e e-mail e desativa quem perdeu o acesso.
- **Quadro "Ligação com o Connect Talk"** na tela Integrações: endereço em uso, quantas pessoas podem entrar e, para quem
  gerencia configurações com acesso a todos os condomínios, o campo **Endereço do Connect Talk**. Ele grava o parâmetro
  global `talk.url` (`ErpSettings`, tipo `url`, só origem http/https) e vale antes do `TALK_URL` do `.env`. Também aparece
  em Configurações › Administrativo.
