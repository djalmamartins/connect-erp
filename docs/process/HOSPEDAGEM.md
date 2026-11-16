# Produção na hospedagem Cloud Startup

Roteiro da etapa 4 de `docs/ROADMAP_V1.md` (ADR 0325). Nada aqui usa credencial real no Git: senhas, chaves e o
`rclone.conf` ficam só no servidor.

## 1. Conferir o plano (primeiro acesso por SSH)

```bash
php -v                                   # 8.2 ou superior
php -m | grep -Ei 'intl|pdo_mysql|openssl|fileinfo|mbstring|gd|zip'
mariadb-dump --version || mysqldump --version
which flock git composer
curl -sI https://cdpj.partners.bancointer.com.br | head -1   # saída liberada para o banco
```

Se faltar extensão, trocar no hPanel (Avançado > Configuração do PHP). Anotar aqui o resultado.

## 2. Domínios

| Subdomínio | Pasta pública |
| --- | --- |
| `www.connectcondominios.com.br` (e o domínio sem www) | site e Central de Ajuda |
| `erp.connectcondominios.com.br` | ERP, API e webhook dos bancos |
| `studio.connectcondominios.com.br` | Studio e Help Desk |
| `app.connectcondominios.com.br` | app web do morador |

Todos apontam para o mesmo front controller do sistema; o roteamento por host é o mesmo do laboratório
(`*.connect.lab`). SSL grátis do hPanel em todos. O código fica **fora** de `public_html`; só a pasta pública é exposta.

## 3. Primeira implantação

```bash
cd ~ && git clone https://github.com/djalmamartins/connect-erp.git connect && cd connect
composer install --no-dev --optimize-autoloader
cp .env.example .env && chmod 600 .env     # preencher: APP_ENV=production, APP_URL, MOVESOS_DB_*, ERP_DEMO_DATA=false
php tools/bank-credentials.php gerar-chave # CONNECT_BANK_VAULT_KEY nova (nunca a do laboratório)
php service/commands/deploy-check.php
composer db:migrate
```

Não subir `apps/` nem `node_modules` (limite de 2 milhões de inodes). Credenciais históricas do laboratório não são
reaproveitadas (`docs/migration/PENDENCIAS.md` item 7).

## 4. Cron (hPanel > Avançado > Tarefas Cron)

```
* * * * *   bash /home/USUARIO/connect/tools/hosting/cron.sh minuto
7 * * * *   bash /home/USUARIO/connect/tools/hosting/cron.sh hora
0 7 * * *   bash /home/USUARIO/connect/tools/hosting/cron.sh dia
30 3 * * *  bash /home/USUARIO/connect/tools/hosting/cron.sh backup
```

Se o `php` do cron não for o 8.2+, definir `PHP_BIN=/caminho/do/php` antes do `bash`. Logs em `storage/logs/cron-*.log`.

## 5. Backup no OneDrive (cifrado)

1. Baixar o rclone para `~/bin/rclone` (binário único) e pôr `RCLONE_BIN=/home/USUARIO/bin/rclone` no `.env`.
2. `rclone config`: criar `onedrive` (tipo OneDrive, conta da Connect; a autorização pede abrir um link no navegador,
   que o Djalma faz uma vez) e por cima `connect-backup` (tipo `crypt`, pasta `onedrive:connect-backup`, senha forte
   gerada pelo rclone).
3. Guardar a senha da cifragem fora do servidor (cofre de senhas do Djalma). Sem ela o backup não abre.
4. `BACKUP_RCLONE_REMOTE=connect-backup:` no `.env` e rodar `bash tools/hosting/backup.sh` uma vez à mão.
5. **Teste de restauração**: `rclone copy connect-backup:diario/<data>/ /tmp/restaura/`, importar o `.sql.gz` num banco
   vazio, abrir o pacote de arquivos e conferir alguns documentos. Anotar data e tempo aqui.

## 6. Atualizar uma versão

```bash
cd ~/connect && bash tools/hosting/backup.sh
git fetch origin && git checkout <commit-novo>
composer install --no-dev --optimize-autoloader && composer db:migrate
php service/commands/deploy-check.php
```

Reversão: `git checkout <commit-anterior>` e, se uma migration quebrou dados, restaurar o backup do passo 1.

## 7. Webhook dos bancos

Sem proxy próprio não dá para exigir o certificado do banco. A rota `/api/v1/webhooks/bank/{banco}/{segredo}` continua
protegida pelo segredo do endereço e pela consulta de cada título no banco antes da baixa; a lista de IPs do banco vai
no `.htaccess` do subdomínio do ERP. A consulta de hora em hora (`cron.sh hora`) dá a baixa mesmo se o webhook falhar.

## 8. Monitoramento (ADR 0333)

Cada grupo do `cron.sh` grava, ao terminar, o sinal de vida em `storage/temp/heartbeat/<grupo>` (hora e quantos comandos
falharam). Dois vigias leem esse sinal:

1. **Por dentro, de hora em hora:** `tools/hosting/watchdog.php` roda no grupo `hora` e manda e-mail para `ALERT_EMAIL`
   (no `.env`) quando uma rotina atrasou ou falhou, o backup passou de 26 horas, avisos da fila falharam nas últimas 24
   horas ou o banco não respondeu. Repete a cada 12 horas enquanto o problema continuar e avisa uma vez quando tudo volta
   ao normal. Testar sem enviar: `php tools/hosting/watchdog.php --dry-run`.
2. **Por fora, a cada 5 minutos:** um monitor externo gratuito (UptimeRobot, Better Stack ou similar) abre
   `https://erp.connectcondominios.com.br/api/v1/health` e procura a palavra-chave `"status":"ready"`. Isso cobre o que o
   vigia de dentro não vê: site fora do ar, banco parado (HTTP 503) e o cron parado de vez (o status vira `degraded`).
   O aviso do monitor vai por e-mail e, se o serviço permitir, por WhatsApp/Telegram.

Prazos: minuto 10 min, hora 2h10, dia e backup 26 h. Rotina que nunca rodou aparece como `unknown` e não dispara alarme
(lab, ou antes de configurar o cron). O `/health` mostra só as palavras (`ready`, `failing`, `stale`, `unknown`), sem
caminho, idade ou erro.
