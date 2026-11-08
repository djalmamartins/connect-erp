# 0023 — Limite de tentativas de login e 2FA por conta e por IP

Data: 2026-11-08 · Status: aceito (rodada autônoma, por delegação do Djalma; aguarda migration no container)

## Contexto

O risco 5 da revisão de segurança da Fase 1 (`docs/security/REVIEW_FASE1_2FA.md`, issue #7):
`request_limit()` guarda o contador na sessão PHP. Quem descobre ou testa senhas pode abrir uma
sessão nova a cada tentativa, e o limite de 5 códigos por desafio do 2FA também é por sessão.

## Decisão

- **Tabela `auth_throttle_events`** (migration `20261108_0901`, reversível): uma linha por falha,
  com `scope` (`login` ou `2fa`), `subject_hash` e `created_at` (Unix). Não é tabela de logs; os
  logs continuam no `AppLogger` (ADR-001).
- **Serviço `Source\Services\LoginThrottle`**, usado por `Auth::login`, `Auth::completeTwoFactor`
  e `Auth::completeTwoFactorSetup`, que servem Studio, ERP e API:
  - login: 10 falhas por conta (e-mail em minúsculas ou CPF/CNPJ só com dígitos) e 30 por IP em
    15 minutos;
  - 2FA: 10 falhas por usuário e 30 por IP em 15 minutos;
  - sucesso apaga as falhas da conta, não as do IP;
  - eventos com mais de 24 horas são apagados a cada falha registrada.
- **LGPD**: conta e IP nunca vão em texto; `subject_hash` é HMAC-SHA256 com a chave
  `CONNECT_2FA_KEY` (sem a chave, o HMAC usa só o prefixo fixo e fica mais fraco).
- **Mensagem única** ("Muitas tentativas...") que não diz se o bloqueio foi da conta ou do IP e
  não revela se a conta existe.
- **Falha aberta**: se a tabela faltar ou o banco falhar, o limitador grava log
  `AUTH_THROTTLE_UNAVAILABLE` e não bloqueia. O limite por sessão continua valendo, e o login não
  pode parar por causa do limitador. O 2FA já falha fechado quando a tabela dele falta (risco 2).
- `request_limit()` por sessão continua nos controllers.

## Consequências

- Um atacante pode bloquear uma conta por 15 minutos errando a senha dela 10 vezes. Aceito por
  ser curto; a mensagem não confirma que a conta existe.
- Atrás de proxy, `REMOTE_ADDR` é o IP do proxy e o limite por IP vira global (30 falhas em 15
  minutos para todos). Antes de publicar atrás de proxy, definir a lista de proxies confiáveis.
- Testes: `LoginThrottleTest` (SQLite em memória). A integração com MariaDB fica para a issue #2.
