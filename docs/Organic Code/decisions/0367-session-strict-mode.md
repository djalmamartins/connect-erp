# ADR 0367 — Sessão só com id criado pelo servidor

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0355 (cabeçalhos de segurança), revisão de segurança do ROADMAP §3.7

## Contexto

O `Source\Core\Session` abre a sessão com cookie `HttpOnly`, `SameSite=Lax` e `Secure` no HTTPS, e o login troca o id
(`session_regenerate_id`). Mas o PHP vem de fábrica com `session.use_strict_mode=0`: aceita qualquer id que chegue no
cookie, inclusive um inventado por terceiro, e cria a sessão com ele. É a porta de entrada da fixação de sessão. O login
já troca o id, então o risco era pequeno; nas telas sem login (formulários públicos, CSRF do visitante) o id inventado
continuava valendo.

## Decisão

Antes do `session_start()`: `session.use_strict_mode=1` (id desconhecido vira um novo) e `session.use_only_cookies=1`
(id nunca pela URL). Não depende do `php.ini` da hospedagem.

## Testes

`SessionStrictModeTest`: servidor embutido do PHP com `use_strict_mode=0` (como de fábrica) e a classe `Session` real.
Cookie com id inventado recebe outro id; o id criado pelo servidor continua valendo na volta. Com o `Session.php`
anterior o teste falha (conferido).
