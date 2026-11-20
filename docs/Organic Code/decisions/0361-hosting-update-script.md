# ADR 0361 — Script de atualização da versão na hospedagem

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0325 (hospedagem e backup), ADR 0354 (deploy-check em produção), ADR 0356 (conferência por HTTP)

## Contexto

O HOSPEDAGEM.md §6 listava os comandos de atualização para digitar um por um pelo SSH, com o `deploy-check` **depois**
da migration e a reversão só descrita ("`git checkout <commit-anterior>`"). Um passo esquecido, ou a migration rodando
com o `.env` errado, só apareceria depois, com o banco já mexido. O ROADMAP §3.4 pedia roteiro de implantação e de reversão.

## Decisão

`tools/hosting/update.sh <commit> [--domain=...]`:

1. Recusa (código 4) se houver arquivo versionado alterado no servidor.
2. Anota o commit atual em `.local/deploy/previous` e roda o `backup.sh`; backup falhou, para (5).
3. `git fetch` e checkout do commit novo (não achou: 6); `composer install --no-dev --optimize-autoloader`.
4. `deploy-check` **antes** da migration. Se ele ou o `composer install` falharem, o script volta sozinho para o commit
   anterior e reinstala as dependências dele (1). O banco ainda não mudou, então a volta é segura.
5. `composer db:migrate`. Se falhar, para (2) sem voltar o código: o banco pode ter mudado em parte e o código antigo pode
   não servir. Mostra o comando para voltar depois de restaurar o backup.
6. `smoke-http.php` com `--domain` (falhou: 3, com o comando de volta).

Tudo vai para `storage/logs/deploy.log`. O script não restaura backup sozinho: restaurar banco é decisão de quem opera.

## Testes

`HostingUpdateScriptTest` roda o script num repositório Git temporário com `backup.sh`, `php` e `composer` falsos:
ordem dos passos no caminho feliz; `deploy-check` falhando volta o HEAD e não chama a migration; migration falhando para
com 2 no commit novo; conferência por HTTP falhando sai com 3; árvore mexida, commit inexistente e sem argumento não fazem
nada. O roteiro aponta para o script.

## Riscos

- `composer install` precisa de rede na hospedagem; se o Packagist falhar, o script volta para o commit anterior (1).
- O checkout fica em HEAD destacado (`--detach`): para saber a versão no ar, `git rev-parse HEAD` ou `.local/deploy/current`.
