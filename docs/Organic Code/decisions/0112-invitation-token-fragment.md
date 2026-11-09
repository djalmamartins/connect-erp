# 0112 — Token do convite fora do caminho e do log de acesso

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão do ciclo 41 (ADR 0092)

## Contexto

O ciclo 41 (ADR 0092) criou a página pública `/convite`. O link do e-mail era `/convite/{token}`, como o
`/forget/{token}`. O caminho vai para o log de acesso do servidor web, e o token ficaria legível ali até ser
usado ou vencer (7 dias). Quem lê o log poderia abrir o convite antes do morador.

## Decisão

1. **Link do e-mail com o token depois do "#".** O dispatcher monta `url('/convite') . '#' . $token`. O navegador
   não envia o fragmento ao servidor, então o token não chega ao log, ao proxy nem ao `Referer`.
2. **Token levado por POST.** Sem convite na sessão, a página `/convite` traz um formulário oculto. Um script
   inline lê o fragmento, confere o formato (64 hex), limpa o endereço com `history.replaceState` e envia o
   token por `POST /convite/abrir`, com CSRF. O controller confere o CSRF e o formato, guarda o token só na
   sessão e redireciona para `/convite`. Corpo de POST não vai para o log `combined`.
3. **Caminho antigo mantido, fora do log.** `GET /convite/{token}` continua funcionando (mesmo efeito do POST,
   sem CSRF, porque só grava o token na sessão de quem abriu). No `docker/app/vhost.conf`, esse caminho fica
   fora do `access.log` (`SetEnvIf` + `env=!invite_token`).
4. As páginas mandam `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private` e `X-Robots-Tag: noindex`.

Conferido sem mudança: `ResidentInvitations::newToken()` e `hashOf()` e as colunas `token_hash`, `status` e
`expires_at` são as mesmas do ciclo 39; o ADR 0111 não mexeu nelas. O papel `user` dado à conta nova só tem
`app.access`, então a conta não abre o ERP nem a API.

## Consequências e lacunas

- Sem JavaScript, o link novo não abre o convite: a pessoa vê a mensagem genérica.
- Cadastro de morador sem CPF/CNPJ aceita qualquer documento válido. Nesse caso, só o token protege a conta.
- O limite de tentativas falha aberto se o banco do limite estiver indisponível (fica registrado no log).
- A configuração do log do servidor de produção fora do Docker não está no repositório. Se ela registrar o
  caminho, aplicar a mesma exclusão do `vhost.conf`.
- As corridas do aceite só têm teste de integração, que roda no Mac.
