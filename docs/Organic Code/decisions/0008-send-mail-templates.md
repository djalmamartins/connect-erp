# 0008 — Templates de e-mail no ambiente send

Data: 2026-11-07 · Status: aceito

## Contexto

`Auth::forget()` e os controllers legados de usuários renderizavam e-mails a partir de
`moves_container_path('mail', CONF_VIEW_MAIL)` → `container/mail/default`, pasta que não
existe (C4 em `docs/architecture/CONNECT_ERP_V1.md`). Djalma definiu em 2026-11-07 que os
e-mails ficam no container `send`, junto com os templates.

Busca feita: `www/connect` (incluindo o histórico Git), `moves`, `mvs`, `code`, `saas` e os
worktrees. Nenhum template `forget`/`confirm` do legado foi encontrado. As pastas
`realizze*` não foram consultadas, porque o contrato proíbe copiar código ou identidade
de lá.

## Decisão

- Ambiente `send`: `container/send/{tema}/v1/`, com `default.php` (moldura única, HTML com
  estilos inline) e `pages/{nome}.php`. Sem `routes.php`, porque e-mail não tem rota HTTP.
- `moves_container_path()`/`moves_container_url()` resolvem `send` e o nome legado `mail`
  para esse caminho. O tema de `settings.view_mail` (alias `mail`/`send` → `default`) passa
  por `moves_safe_theme()`, restrito a `[a-z0-9_-]`.
- `Source\Support\MailTemplate` é o único ponto de renderização. Aceita só nomes
  `[a-z0-9-]` e exige que o arquivo exista. O envio continua em `Support/Email`.
- Templates escritos do zero: `forget` (recuperação de senha) e `confirm` (confirmação de
  cadastro), com escape de todas as variáveis.
- `Auth::forget()` usa `MailTemplate`. Os controllers `Erp/{V1,Connect}/Users` (legados,
  congelados por C11 e sem rota) não foram alterados e continuam sem template válido.

## Consequências

- A recuperação de senha volta a ter template quando a rota `/forget` existir. Hoje ela
  não está registrada no `routes.php` do WEB.
- Riscos registrados no fluxo existente, não alterados aqui: o token de `forget` usa
  `md5(uniqid(rand()))` e o link leva o e-mail na URL.
- Testes: `tests/legacy/Unit/MailTemplateTest.php`.
