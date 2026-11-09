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
  - login: 6 falhas por conta (e-mail em minúsculas ou CPF/CNPJ só com dígitos) e 30 por IP em
    15 minutos;
  - 2FA: 6 falhas por usuário e 30 por IP em 15 minutos;
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

- Um atacante pode bloquear uma conta por 15 minutos errando a senha dela 6 vezes. Aceito por
  ser curto; a mensagem não confirma que a conta existe.
- Atrás de proxy, `REMOTE_ADDR` é o IP do proxy e o limite por IP vira global (30 falhas em 15
  minutos para todos). Resolvido no adendo abaixo.
- Testes: `LoginThrottleTest` (SQLite em memória). A integração com MariaDB fica para a issue #2.

## Adendo de 09/11/2026: IP atrás de proxy

- `Source\Support\ClientIp::resolve()` dá o IP usado pelo limitador. Por padrão vale só
  `REMOTE_ADDR`; `X-Forwarded-For` é ignorado, porque o cliente pode forjá-lo.
- `CONNECT_TRUSTED_PROXIES` (IPs ou CIDR, IPv4 e IPv6, separados por vírgula) liga a leitura do
  cabeçalho: se `REMOTE_ADDR` for um proxy confiável, o IP do cliente é o primeiro endereço não
  confiável de `X-Forwarded-For` lido da direita para a esquerda. Valor inválido no cabeçalho
  volta para `REMOTE_ADDR`.
- Antes de publicar atrás de proxy (Docker, Nginx, Cloudflare), definir a variável no `.env` do
  ambiente, fora do Git. Testes: `ClientIpTest`.
