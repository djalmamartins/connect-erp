# ADR 0366 — Erros do PHP fora da tela em produção

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0354 (deploy-check: APP_DEBUG=false em produção), ADR 0355 (cabeçalhos de segurança)

## Contexto

O `deploy-check` exige `APP_DEBUG=false` em produção, mas nada no código usava essa chave para esconder os erros do
PHP: `display_errors` ficava como o `php.ini` da hospedagem mandasse. Se viesse ligado, um aviso (variável indefinida,
índice ausente) sairia no meio da página com o caminho completo do arquivo no servidor. O cabeçalho `X-Powered-By`
também anunciava a versão do PHP.

## Decisão

- `Source\Support\ErrorDisplay::apply(CONF_APP_DEBUG)` logo depois do autoload no `index.php`.
- Sem debug, na web: `display_errors=0`, `display_startup_errors=0`, `log_errors=1` (o `AppLogger` continua registrando
  tudo com o código do incidente) e `header_remove('X-Powered-By')`.
- Com debug (laboratório) nada muda. No CLI (cron, ferramentas) nada muda: o erro precisa aparecer no log do cron.
- Erro de sintaxe antes do `index.php` rodar não é coberto aqui; isso depende do `php.ini` (o roteiro do hPanel já
  pede conferir a configuração do PHP).

## Testes

`ErrorDisplayTest`: diretivas sem e com debug; CLI intocado; chamada no `index.php` depois do autoload e antes do
`AppLogger`; servidor embutido com `display_errors=1` e `error_reporting=-1`: sem debug a página sai limpa, com debug o
aviso aparece. Sem a chamada, o teste do servidor falha (conferido). O servidor embutido não manda `X-Powered-By`, então
a remoção desse cabeçalho é conferida só no código.
