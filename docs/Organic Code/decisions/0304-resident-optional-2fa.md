# 0304 — Verificação em duas etapas opcional para o morador

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0302 (aplicativo do morador), ADR 0303 (aplicativo nativo, sessão por token), ADR 0025
  (reset do 2FA), Fase 1 do 2FA (`TwoFactorService`, `CONNECT_2FA_ENFORCE_FROM`)
- **Numeração:** faixa avulsa 0300–0319.

## Contexto

Pedido do Djalma (14/11/2026): no aplicativo do morador, web e React Native, o morador não deve ser obrigado a
fazer a verificação em duas etapas, "porque ele não vai mexer com dinheiro". Se quiser, liga no perfil.

Até aqui, com `CONNECT_2FA_ENFORCE_FROM` vencido, qualquer conta sem 2FA caía na configuração obrigatória no login
web (`Auth::secondFactorMode` = "setup") e o app nativo recebia 409 `two_factor_setup_required`.

## Decisão

- **Conta só de morador** (`ResidentTwoFactor::isResidentOnly`): tem cadastro de morador ativo (`erp_residents`
  com `user_id`, não excluído), nível menor que 5, nenhum papel além de `user` e nenhuma permissão liberada por
  exceção (`access_user_overrides` com `allow`). Essa conta fica fora da obrigatoriedade
  (`ResidentTwoFactor::enforcedFor`), no login web (`Auth`) e no app nativo (`ResidentAppSessions`).
- Conta da equipe que também mora continua obrigada, porque a mesma sessão entra no ERP. Erro na consulta = regra
  obrigatória (fail-closed).
- Se o morador ligar o 2FA, o código é pedido como para todo mundo (desafio 202 do app nativo, `/studio/login/2fa`
  na web). Nada muda no contrato do login.
- **Perfil:** tela `Segurança da conta` no app web (`/seguranca`, link em Minha unidade) e rotas
  `GET /api/v1/resident/2fa`, `POST /api/v1/resident/2fa/confirm|disable|recovery` (sessão + CSRF ou token Bearer),
  para o app nativo desenhar o mesmo perfil. Mesmas regras do ERP (`Erp\Connect\Security`): desligar e renovar os
  códigos pedem o código atual, aviso por e-mail (`TwoFactorNotice`), registro no log de autenticação e limite de
  tentativas no `LoginThrottle` (escopo 2FA, por conta e IP, vale também sem sessão). Conta obrigada recebe 409
  `two_factor_required` ao tentar desligar. Erros no padrão `{error:{code,message}}` (422 `invalid_code`, 429
  `too_many_attempts`, 409 `already_enabled` e `not_enabled`).

## Consequências

- O morador entra só com e-mail e senha, mesmo depois da data de obrigatoriedade.
- Uma conta de morador que recebe papel de equipe passa a ter o 2FA obrigatório no próximo login.
- Testes: `Unit\ResidentTwoFactorTest` e `Integration\ResidentTwoFactorIntegrationTest`.
