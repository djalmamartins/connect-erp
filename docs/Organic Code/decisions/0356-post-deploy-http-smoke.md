# ADR 0356 — Conferência por HTTP depois de publicar

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0333 (/api/v1/health), ADR 0338 (pastas bloqueadas), ADR 0354 (deploy-check), ADR 0355 (cabeçalhos)

## Contexto

O roteiro de implantação (ROADMAP §3.4) pede "deploy-check, migrations, smoke HTTP e reversão". O smoke HTTP só existia
dentro do `tools/testar-main.sh`, preso aos endereços `*.connect.lab` e ao Docker do Mac. Na hospedagem, a conferência
depois de publicar era um `curl -I` solto no HOSPEDAGEM.md.

## Decisão

`php tools/hosting/smoke-http.php --domain=connectcondominios.com.br`, com as regras em `Source\Support\SmokeHttp`:

- Só GET, sem login, sem gravar nada. Roda do Mac ou do servidor, sem `.env` e sem banco (não carrega o autoload).
- Confere nos 4 endereços: `/api/v1/health` com status `ready`; site, Central de Ajuda, login do ERP e verificação
  pública de certidão com 200; painel do ERP sem login mandando para o login; Studio e app do morador respondendo.
- `http://` indo para `https://`.
- `.git/config`, `.env`, `composer.json`, `tools`, `docs`, `database`, `storage/logs` e `.local` com 403 ou 404, sem
  mostrar o conteúdo.
- Toda página com `nosniff`, `X-Frame-Options`, HSTS e `Referrer-Policy` (ADR 0355).
- Sai com código 1 se algo falhar; a reversão segue o HOSPEDAGEM.md §6.

## Testes

- `SmokeHttpTest`: os 4 endereços, as pastas barradas, health `ready`/`degraded`/503, HTTPS, conteúdo vazado, cabeçalho
  faltando, leitura dos cabeçalhos da última resposta e que a ferramenta não usa POST nem autoload.
- Rodado contra o servidor embutido do PHP com os endereços do laboratório: health, site, Central de Ajuda, login do ERP,
  certidão e Studio certos. O painel e o app deram 500 também sem esta mudança (o banco de teste não tem a configuração
  do lab); no Mac e na hospedagem valem os códigos acima. As pastas barradas dependem do `.htaccess`, que o servidor
  embutido não lê: conferir na hospedagem.
