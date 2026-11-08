# Revisão de segurança — Fase 1 (2FA TOTP e escopo por condomínio)

Data: 07/11/2026. Escopo: commit `e007c18` e ajustes seguintes no `claude-dev`
(`source/Support/Totp.php`, `SecretBox.php`, `Services/TwoFactorService.php`,
`Services/CondominiumScope.php`, `Models/Auth.php`, `Controllers/Studio/TwoFactor.php`,
`Controllers/Api/V1/*`, `Support/Middleware/ApiAuthenticated.php`, migrations `20261107_*`).
Revisão por leitura de código; os testes de integração dependem do banco (rodar no container).

## Verificado

| Controle | Situação |
|---|---|
| Elevação só após o 2FA | Senha correta grava `auth2faPending` sem `authUser`; `authUser` só é definido em `finishLogin()`, depois do código. Rotas autenticadas checam `authUser`. |
| Fixação de sessão | `session_regenerate_id(true)` ao criar o desafio e ao concluir o login. |
| Segredo TOTP | AES-256-GCM com nonce aleatório e AAD por usuário (`user_two_factor:{id}`); chave `CONNECT_2FA_KEY` fora do Git. Testes unitários cobrem adulteração, contexto e chave errados. |
| Replay de código | `last_used_step` com `UPDATE ... WHERE last_used_step < :step` (atômico); código já usado é rejeitado. |
| Força bruta | 5 tentativas por desafio (expira em 5 min) + `request_limit` por sessão no Studio (6/5 min) e na API (6/5 min). |
| Códigos de recuperação | 10 códigos, `password_hash`, uso único com `UPDATE ... AND used_at IS NULL`; exibidos uma vez. |
| Enumeração de contas | Mensagem única "Credenciais inválidas" para e-mail/CPF inexistente e senha errada. |
| CSRF | Formulários do Studio usam `csrf_verify`; API exige `X-CSRF-Token` (`hash_equals`) em métodos que alteram estado e no verify. |
| XSS | Views do 2FA escapam segredo, URI e códigos com `htmlspecialchars`. |
| SQL | Todas as consultas novas usam prepared statements; `IN (...)` montado só com placeholders. |
| Escopo por condomínio | `CondominiumScope::hasGlobalAccess` não usa o fallback por nível legado: sem permissão explícita, nega. Acesso fora do escopo vira 404 na API e gera log `CONDOMINIUM_SCOPE_DENIED`. |
| Logs | Eventos de 2FA no canal `authentication` sem código, segredo ou senha. |

## Riscos aceitos / pendências

1. **Cadastro obrigatório no primeiro login (modo `setup`)**: quando `CONNECT_2FA_ENFORCE_FROM` já passou, quem só tem a senha consegue cadastrar o autenticador. Mitigação: ativar a obrigatoriedade depois que a equipe cadastrar o 2FA no período de adesão.
2. **Tabela ausente**: se a migration `20261107_0001` não estiver aplicada, o login seguia sem 2FA (fail-open para `42S02`, com log `TWO_FACTOR_SCHEMA_MISSING`). **Ajustado em 08/11/2026** (issue #7): depois de `CONNECT_2FA_ENFORCE_FROM` o login falha fechado com "acesso temporariamente indisponível"; antes dessa data continua sem 2FA, porque ninguém poderia ter cadastrado o autenticador. `TwoFactorSchemaMissingTest` cobre as duas situações.
3. **Enumeração por tempo**: ~~e-mail inexistente responde sem `password_verify`~~. **Corrigido em 08/11/2026** (issue #7): `Auth::attempt` verifica a senha contra `Auth::DUMMY_PASSWORD_HASH` (bcrypt custo 10) quando o e-mail/CPF não existe; `AuthDummyHashTest` garante algoritmo e custo iguais aos de `passwd()`.
4. **Reset de 2FA por administrador** (`security.2fa.manage`): permissão criada, tela ainda não implementada. Até lá, reset é manual e deve ser auditado.
5. **Rate limit por sessão**: ~~`request_limit` usa a sessão; um atacante com a senha pode abrir novas sessões~~. **Corrigido em 08/11/2026** (issue #7, ADR 0023): `LoginThrottle` conta falhas de login e 2FA por conta e por IP na tabela `auth_throttle_events` (HMAC, sem texto), além do limite por sessão. `LoginThrottleTest` cobre bloqueio, janela, limpeza e falha aberta.
6. **QR code**: não há gerador de QR no projeto; o cadastro usa chave manual e link `otpauth://`. Fica para o design system (`claude-layout`).
7. **Controller ERP legado** (`Controllers/Erp/Connect/Erp.php`): ~~aceita `condominium_id` sem checar escopo e cai no condomínio `0001`~~. **Corrigido em 08/11/2026** (issue #7): `Erp::resolveCondominium` só aceita o id pedido ou o salvo em `session_condo` quando `CondominiumScope::canAccess` permite; fora do escopo, id inválido ou erro na consulta limpam `authCondo` em vez de usar um condomínio padrão. `ErpLegacyCondominiumScopeTest` cobre os casos. O controller continua sem rota e será substituído pelo módulo Condomínios.

## Testes

- Unitários (sem banco, `composer test:unit:offline`): 44 testes, 131 asserções, todos passando em 07/11/2026, incluindo vetores RFC 6238, janela, replay, base32, `SecretBox` e data de obrigatoriedade.
- Integração (`TwoFactorServiceTest`, `CondominiumScopeTest`, `AuthTest`): exigem MariaDB; não executados nesta sessão.
