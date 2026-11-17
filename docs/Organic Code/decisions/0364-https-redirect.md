# ADR 0364 — http:// para https:// em produção

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0355 (cabeçalhos de segurança e HSTS), ADR 0356 (conferência por HTTP), ADR 0357 (.env de produção)

## Contexto

O HSTS (ADR 0355) só vale depois que o navegador visita o site por HTTPS uma vez. Nada mandava quem digita
`http://erp.connectcondominios.com.br` para o HTTPS: nem o `.htaccess`, nem o código, nem o roteiro da hospedagem. A
tela de login e o cookie de sessão podiam trafegar em texto aberto. O `smoke-http.php` (ADR 0356) já confere que
`http://` redireciona, mas não havia quem fizesse o redirecionamento.

## Decisão

- `Source\Support\HttpsRedirect::handle()` no `index.php`, **antes** de abrir a sessão (nenhum cookie sai por HTTP).
- Só com `APP_ENV=production`. Laboratório e testes não mudam.
- Considera HTTPS: `HTTPS` ligado, `X-Forwarded-Proto: https`, `REQUEST_SCHEME=https` ou porta 443.
- GET/HEAD: 301; outros métodos: 308 (mantém método e corpo, para o webhook e formulários).
- Só redireciona para o domínio do `APP_URL` (ele mesmo ou um subdomínio; `www.`, `erp.`, `app.`, `studio.` e `talk.`
  são tirados para achar o domínio). Host de fora, vazio ou com caracteres estranhos: segue sem redirecionar. Caminho
  com `//` no começo ou quebra de linha vira `/`.
- `CONNECT_FORCE_HTTPS=false` no `.env` desliga, se um proxy na frente não informar o HTTPS e o site entrar em laço.
  No modelo de produção fica `true`.

## Testes

- `HttpsRedirectTest`: 301/308 no mesmo host e caminho; nada quando já é HTTPS (4 formas), fora de produção ou desligado;
  nunca para host de fora do domínio (inclui `dominio.evil` e `evildominio`); caminho perigoso vira `/`; domínio tirado
  do `APP_URL`; chamada antes da sessão no `index.php`.
- À mão com o `index.php` completo no servidor embutido e `APP_ENV=production`: GET 301 e POST 308 para `https://erp...`;
  Host de fora não redireciona; com `X-Forwarded-Proto: https` o `/api/v1/health` responde 200.
