# 0009 — Recuperação de senha com token aleatório, hash e uso único

Data: 2026-11-07 · Status: aceito (aguarda testes de integração no container)

## Contexto

O fluxo `/forget` estava fora do `routes.php` do WEB. `Auth::forget()` gerava o código com
`md5(uniqid(rand()))`, guardava em `users.forget` em texto e montava o link
`/forget/{codigo}:{email}`, com o e-mail na URL. A resposta também revelava se o e-mail ou
CPF estava cadastrado. Esses riscos foram registrados no ADR 0008.

## Decisão

- Rotas WEB (`container/web/default/v1/routes.php`): `GET|POST /forget` → `Web:forget`,
  `GET /forget/{code}` → `Web:reset` e `POST /reset` → `Web:reset`.
- Token: `bin2hex(random_bytes(32))` (`Source\Support\PasswordResetToken`). Só o
  SHA-256 vai para a nova tabela `user_password_resets` (migration
  `20261107_0004_password_resets`), com validade de 30 minutos e uso único. Um pedido novo
  invalida os anteriores.
- `Source\Services\PasswordResetService` cuida de `issue`, `findValidUserId` e `consume`.
  O consumo é condicional (`used_at IS NULL`).
- `Auth::resetWithToken()` consome o token e grava a senha na mesma transação: se a senha
  não for salva, o token continua válido. A validação de senha roda antes do consumo.
- O link leva apenas o token (`/forget/{token}`), e o usuário é encontrado pelo hash.
- `Auth::forget()` responde igual para conta existente ou não. `Web:forget` mostra uma
  única mensagem genérica e só diferencia formato inválido de e-mail ou CPF.
- Limite por sessão: 5 pedidos e 10 tentativas de troca a cada 15 minutos (`request_limit`).
- Após a troca, o redirecionamento vai para `/studio/login`, o login ativo hoje (`/login` não
  está registrado).
- `Auth::reset()` e `users.forget` continuam como estavam, para o fluxo legado de confirmação
  de cadastro (`Web:confirmation`, sem rota).

## Consequências

- Os links do formato antigo `/forget/{md5}:{email}` deixam de funcionar.
- Tokens pendentes guardam `requested_ip`. Os registros expirados não são apagados
  automaticamente; uma limpeza periódica pode ser feita depois.
- Testes: `tests/legacy/Unit/PasswordResetTokenTest.php` (geração, formato, hash,
  expiração, rotas) e `tests/legacy/Integration/PasswordResetServiceTest.php` (uso único,
  expiração, invalidação, troca atômica, resposta igual para conta inexistente).
