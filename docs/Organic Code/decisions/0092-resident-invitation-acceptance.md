# 0092 — Aceite do convite do morador na página pública /convite

Data: 2026-11-09 · Status: proposto para revisão de segurança · Página: AP-152 (continuação do ADR 0090)

## Contexto

O convite de morador (ADR 0090, com as correções do ADR 0111) é entregue com o link `/convite/{token}`.
Faltava a página que recebe o link e cria a conta. Sem ela, o envio real não pode ser ligado.

## Decisão

- **GET /convite/{token}:**
  - se o token tem o formato certo (64 hex), ele vai para a sessão, e a resposta redireciona para
    `/convite`;
  - assim o token sai da URL antes de qualquer formulário e não vai em Referer, histórico ou nos
    links seguintes.
- **GET /convite:**
  - se o convite ainda vale (pendente, não vencido, de morador ativo com e-mail), mostra o nome e o
    e-mail mascarado;
  - em qualquer outro caso, a mesma mensagem genérica, sem dizer se o token não existe, venceu, foi
    usado ou revogado.
- **POST /convite:**
  - CSRF e limite de 20 tentativas por IP e 5 por token em 15 minutos, em `auth_throttle_events` (escopo
    `invite`, só HMAC, como o ADR 0023);
  - pede o CPF ou CNPJ, que precisa ser igual ao do cadastro quando o cadastro tem, além da senha;
  - a senha segue o tamanho do Studio (`CONF_PASSWD_MIN_LEN` e `CONF_PASSWD_MAX_LEN`), e um valor com
    formato de hash é recusado.
- **Aceite atômico, numa transação:**
  - trava o convite (FOR UPDATE);
  - cria a conta comum: nível 1, papel `user` se existir (fail-closed se não existir), status
    confirmado;
  - marca o convite como aceito (UPDATE condicionado);
  - liga o morador à conta (`erp_residents.user_id`, migration 0545, único).

  Qualquer falha desfaz tudo. Se já houver conta com o mesmo e-mail ou documento, a página orienta a
  usar o login; nada é ligado automaticamente.
- **O que não muda:** não há login automático. Depois do aceite, a pessoa vai para o login normal, com
  o 2FA e a sessão do Studio, que não mudam.
- **Cabeçalhos** nas três respostas: `Referrer-Policy: no-referrer`, `Cache-Control: no-store` e
  `X-Robots-Tag: noindex`.
- **Nada de token** em log ou na auditoria. O limite guarda o HMAC do sha256 do token.

## Consequências

- O primeiro GET leva o token no caminho. Isso é inevitável num link de e-mail, e o token pode ficar no
  log de acesso do servidor web. O risco é reduzido por: uso único, 7 dias, redirecionamento imediato e
  a exigência do CPF/CNPJ do cadastro no POST. No alinhamento, avaliar se o log de acesso registra o
  caminho completo.
- As rotas entram no alinhamento (docs/handoff-0092-shared.md).
- Feito sem acesso ao commit 691fa3b: o código usa o `ResidentInvitations` do ciclo 39 como
  referência, só pelas funções `newToken` e `hashOf`, e faz o aceite com o próprio UPDATE condicionado.
  No alinhamento, conferir com o ADR 0111.
