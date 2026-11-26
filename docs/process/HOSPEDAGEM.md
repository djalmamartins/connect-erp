# Produção na hospedagem Cloud Startup

Roteiro da etapa 4 de `docs/ROADMAP_V1.md` (ADR 0325). Nada aqui usa credencial real no Git: senhas, chaves e o
`rclone.conf` ficam só no servidor.

## 1. Conferir o plano (primeiro acesso por SSH)

```bash
php -v                                   # 8.2 ou superior
php -m | grep -Ei 'intl|pdo_mysql|openssl|fileinfo|mbstring|gd|zip|curl|dom|simplexml'   # composer check-platform-reqs confere tudo
mariadb-dump --version || mysqldump --version
which flock git composer
curl -sI https://cdpj.partners.bancointer.com.br | head -1   # saída liberada para o banco
```

Se faltar extensão, trocar no hPanel (Avançado > Configuração do PHP). Anotar aqui o resultado.

Na mesma tela, deixar `memory_limit` em 512M ou mais (ADR 0384: o livro-caixa de um mês cheio passa de 256M), `upload_max_filesize` em 50M ou mais (o maior arquivo que o sistema
aceita) e `post_max_size` maior que ele. O `deploy-check` avisa se algum ficar abaixo (ADR 0362). Se mesmo assim um
envio passar do `post_max_size`, o sistema avisa o limite em MB em vez de "sessão expirada" (ADR 0363).

## 2. Domínios

| Subdomínio | Pasta pública |
| --- | --- |
| `www.connectcondominios.com.br` (e o domínio sem www) | site e Central de Ajuda |
| `erp.connectcondominios.com.br` | ERP, API e webhook dos bancos |
| `studio.connectcondominios.com.br` | Studio e Help Desk |
| `app.connectcondominios.com.br` | app web do morador |

Todos apontam para o mesmo front controller do sistema; o roteamento por host é o mesmo do laboratório
(`*.connect.lab`). SSL grátis do hPanel em todos. Com `APP_ENV=production`, o próprio sistema manda `http://` para `https://` (301; 308 em
POST) no mesmo endereço do domínio do `APP_URL` (ADR 0364); `CONNECT_FORCE_HTTPS=false` desliga se der laço. O código fica **fora** de `public_html`; só a pasta pública é exposta.
Como o `index.php` e os assets moram na raiz do projeto, a pasta pública costuma ser a própria pasta do projeto: o
`.htaccess` barra `.git`, `.local` (backups), `tools`, `docs`, `database` e o código dos temas (ADR 0338). Depois de
implantar, conferir com `php tools/hosting/smoke-http.php --domain=connectcondominios.com.br` (ADR 0356): páginas,
`/api/v1/health`, HTTPS, pastas barradas (`.git/config` precisa responder 403 ou 404) e cabeçalhos de segurança.

## 3. Primeira implantação

```bash
cd ~ && git clone https://github.com/djalmamartins/connect-erp.git connect && cd connect
composer install --no-dev --optimize-autoloader
cp .env.production.example .env && chmod 600 .env   # preencher tudo marcado com <...> (ADR 0357)
php tools/bank-credentials.php gerar-chave # CONNECT_BANK_VAULT_KEY nova (nunca a do laboratório)
composer db:install    # banco vazio: estrutura base, catálogos, configuração e migrations (ADR 0396)
php tools/hosting/criar-admin.php --email=<seu e-mail> --nome=<nome> --sobrenome=<sobrenome> --cpf=<cpf>   # ADR 0380
php service/commands/deploy-check.php
```

O `criar-admin` mostra a senha uma vez só e só funciona enquanto não houver administrador; as outras contas saem pela tela
Usuários do Sistema.

**Banco vazio (ADR 0379 e 0396):** `composer db:install` só roda num banco sem nenhuma tabela. Carrega a estrutura
exportada do laboratório (`database/base/schema.sql`, sem dados), os catálogos das migrations antigas (papéis, permissões,
versões), a configuração inicial (`settings`, com o `APP_URL`) e as migrations atuais. Nas atualizações seguintes, só
`composer db:migrate`. Se o laboratório ganhar tabela nova fora de migration, rode de novo no Mac
`bash tools/lab-exportar-esquema.sh` e mande `database/base/` num PR.

O `deploy-check` com `APP_ENV=production` (ADR 0354) para com erro se o `.env` estiver inseguro (debug, dados de
exemplo, endereço do laboratório, `CONNECT_2FA_KEY` ou `CONNECT_BANK_VAULT_KEY` ausente ou repetida) ou se houver conta de
teste no banco, e avisa do que o roteiro ainda pede (2FA obrigatório, `ALERT_EMAIL`, e-mail, equipe sem 2FA).

Se as imagens antigas do site forem copiadas para `storage/images`, rode `php tools/hosting/limpar-exif.php` (só conta) e
`--aplicar` para tirar o GPS e o aparelho das fotos (ADR 0390); as imagens enviadas pelo sistema já saem limpas (ADR 0389).

Não subir `apps/` nem `node_modules` (limite de 2 milhões de inodes). Credenciais históricas do laboratório não são
reaproveitadas (`docs/migration/PENDENCIAS.md` item 7).

## 4. Cron (hPanel > Avançado > Tarefas Cron)

```
* * * * *   bash /home/USUARIO/connect/tools/hosting/cron.sh minuto
7 * * * *   bash /home/USUARIO/connect/tools/hosting/cron.sh hora
0 7 * * *   bash /home/USUARIO/connect/tools/hosting/cron.sh dia
30 3 * * *  bash /home/USUARIO/connect/tools/hosting/cron.sh backup
```

O Connect Talk **não roda na hospedagem** (Djalma, 17/11/2026, ADR 0359): volta a ser o projeto separado
(`djalmamartins/talk`), inteiro num Raspberry Pi no escritório com nobreak, e conversa com o ERP pelas rotas `/talk/...` da API, com o token `ctk_` (ADR 0308). Por isso não
há cron do `tools/talk-worker.php` no hPanel. Os e-mails da fila saem pelo SMTP quando o `.env` tem `ERP_MAIL_DELIVERY=production` (ADR 0358);
o `cron.sh` lê do `.env` essa chave, `MAIL_QUEUE_SEND` e `PHP_BIN`.

**Conferir o e-mail (ADR 0381)** depois de criar a conta de e-mail no hPanel e preencher o SMTP no `.env`:
`php tools/hosting/email-teste.php --para=seu@email --dkim-seletor=SELETOR` (o seletor aparece no hPanel, em E-mails >
Autenticação). Ele mostra o que falta no DNS (SPF, DKIM, DMARC) e manda um e-mail de teste; conferir se chegou fora do spam.
Anote o seletor em `MAIL_DKIM_SELECTOR` no `.env`: o `deploy-check` passa a avisar a cada atualização se o DNS de e-mail
mudar (ADR 0382). Comece o DMARC com `p=none` e passe para `quarantine` depois de algumas semanas sem falha.

Se o `php` do cron não for o 8.2+, definir `PHP_BIN=/caminho/do/php` antes do `bash`. Logs em `storage/logs/cron-*.log`.

## 5. Backup no OneDrive (cifrado)

1. Baixar o rclone para `~/bin/rclone` (binário único) e pôr `RCLONE_BIN=/home/USUARIO/bin/rclone` no `.env`.
2. `rclone config`: criar `onedrive` (tipo OneDrive, conta da Connect; a autorização pede abrir um link no navegador,
   que o Djalma faz uma vez) e por cima `connect-backup` (tipo `crypt`, pasta `onedrive:connect-backup`, senha forte
   gerada pelo rclone).
3. Guardar a senha da cifragem fora do servidor (cofre de senhas do Djalma). Sem ela o backup não abre.
4. `BACKUP_RCLONE_REMOTE=connect-backup:` no `.env` e rodar `bash tools/hosting/backup.sh` uma vez à mão.
5. **Teste de restauração** (ADR 0334): criar no hPanel um banco vazio só para isso, com "restore" no nome (ex.:
   `u123_connect_restore`) e o mesmo usuário do banco do sistema, e pôr `BACKUP_RESTORE_DB=u123_connect_restore` no
   `.env`. Se o hPanel criar um usuário só para esse banco (é o normal na Hostinger), pôr também `BACKUP_RESTORE_USER` e
   `BACKUP_RESTORE_PASS`; se cortar o nome (`..._connect_restor`), tudo bem: a trava aceita "restor" (ADR 0651).
   Depois rodar `bash tools/hosting/restore-check.sh --remoto`: baixa o diário mais novo do OneDrive, restaura
   nesse banco, confere o pacote de arquivos e compara tabela por tabela com o banco real. Sai `OK` ou `FALHOU` com o
   tempo, e a linha fica em `storage/logs/restore-check.log`. O script recusa o banco de produção e qualquer nome sem
   "restore". Repetir uma vez por mês e anotar aqui.

   | Data | Backup | Tempo | Resultado |
   |---|---|---|---|
   | 16/11/2026 | banco de teste do ambiente de desenvolvimento (165 tabelas) | 4 s | OK (teste do script, não da hospedagem) |

## 6. Atualizar uma versão

```bash
cd ~/connect && bash tools/hosting/update.sh <commit-novo> --domain=connectcondominios.com.br
```

O script (ADR 0361) faz, em ordem: recusa se algum arquivo versionado foi mexido no servidor; anota o commit atual em
`.local/deploy/previous`; backup (`backup.sh`); `git fetch` e checkout do commit novo; `composer install --no-dev`;
`deploy-check`; `composer db:migrate`; conferência por HTTP (`smoke-http.php`, ADR 0356). Tudo fica em
`storage/logs/deploy.log`. Códigos de saída:

| Código | O que aconteceu | O que fazer |
| --- | --- | --- |
| 0 | versão nova no ar | nada |
| 1 | `composer install` ou `deploy-check` falhou | **o script já voltou** para o commit anterior; o banco não mudou. Corrigir o `.env` ou o código e repetir |
| 2 | a migration falhou | o banco pode ter mudado em parte: restaurar o backup do passo 1 (§5) e rodar `bash tools/hosting/update.sh <commit-anterior>` |
| 3 | a conferência por HTTP falhou | ver o que falhou; para voltar o código, `bash tools/hosting/update.sh <commit-anterior>` (com migration nova, restaurar o backup antes) |
| 4, 5, 6 | arquivo mexido no servidor, backup falhou ou commit não encontrado | nada foi feito; resolver e repetir |

O commit anterior aparece na saída e fica em `.local/deploy/previous`.

## 7. Webhook dos bancos

Antes de tudo, confira que a hospedagem consegue falar com o banco usando o certificado (mTLS pelo cURL do PHP):
`php tools/bank-credentials.php testar --conta=ID` pede só um token ao banco da conta (sandbox do Inter ou stage da Cora),
sem emitir nada. Saída 0 = o banco respondeu; 1 = mostra o motivo (ADR 0392).

Sem proxy próprio não dá para exigir o certificado do banco. A rota `/api/v1/webhooks/bank/{banco}/{segredo}` continua
protegida pelo segredo do endereço e pela consulta de cada título no banco antes da baixa. A lista de IPs do banco vai
no `.env`, em `BANK_WEBHOOK_ALLOWED_IPS` (IPs ou faixas CIDR separados por vírgula, ADR 0360): chamada de fora da lista
recebe 404, como se o endereço não existisse, e fica no log como `bank_webhook_ip_blocked`. Preencher com os IPs que o
Inter e a Cora informarem; vazio aceita qualquer origem. A consulta de hora em hora (`cron.sh hora`) dá a baixa mesmo se o webhook falhar.

## 8. Monitoramento (ADR 0333)

Cada grupo do `cron.sh` grava, ao terminar, o sinal de vida em `storage/temp/heartbeat/<grupo>` (hora e quantos comandos
falharam). Dois vigias leem esse sinal:

1. **Por dentro, de hora em hora:** `tools/hosting/watchdog.php` roda no grupo `hora` e manda e-mail para `ALERT_EMAIL` (um ou mais, separados por vírgula: sac@connectcondominios.com.br e djalma.martins@moves.com.br, Djalma 23/11)
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

Faxina diária (ADR 0336, `cron.sh dia`): os logs `storage/logs/movesos-*.log` são compactados depois de 2 dias e apagados
depois de 6 meses; o espaço usado é medido e o vigia avisa ao passar de 85% de `HOSTING_DISK_LIMIT_GB`. O vigia também
avisa quando o certificado HTTPS do `APP_URL` vence em menos de 15 dias. Testar sem mexer: `php tools/hosting/housekeeping.php --dry-run`.

Certificado dos bancos (ADR 0352): o vigia avisa 30 dias antes de vencer o certificado do Inter ou da Cora enviado em
Configurações › Integrações, e de novo no dia em que vence. Sem ele a emissão e a consulta dos boletos param. Para trocar:
gerar o certificado novo no internet banking e enviar pela mesma tela.

## 9. Teste de carga dos limites de tentativas (ADR 0348)

Depois da primeira implantação, e antes de abrir para os moradores, rodar do Mac contra o servidor novo (ainda sem
moradores) para conferir que os limites de login seguram tentativas simultâneas:

```
php tools/hosting/load-test-limits.php --base=https://erp.connectcondominios.com.br --path=/login --total=100 --parallel=20
php tools/hosting/load-test-limits.php --base=https://erp.connectcondominios.com.br --path=/login --total=150 --parallel=20 --vary-email
```

Esperado: no primeiro, `aceitos` igual a 6 (limite por conta); no segundo, até 30 (limite por IP no banco; o contador em
arquivo corta em 40). O seu IP fica bloqueado por 15 minutos depois do teste. Não rodar contra o servidor com moradores
usando: quem estiver no mesmo IP do teste também fica bloqueado.

## 10. Arquivos no OneDrive da Connect (ADR 0373/0374)

Padrão: `DOCUMENT_STORAGE=local` (pasta privada do servidor). Para guardar os documentos, fotos de vistoria e fotos dos
moradores no OneDrive empresarial da Connect (precisa da conta Microsoft 365 Business no domínio):

1. Entra ID (portal.azure.com > Microsoft Entra ID > Registros de aplicativo > Novo registro): nome "Connect ERP
   arquivos", conta só desta organização. Anote o **ID do diretório (locatário)** e o **ID do aplicativo (cliente)**.
2. Certificados e segredos > Novo segredo do cliente (24 meses). Copie o **valor** na hora (ele não aparece de novo) e
   anote no calendário a data de troca.
3. Permissões de API > Microsoft Graph > Permissões de aplicativo > `Files.ReadWrite.All` > Conceder consentimento de
   administrador.
4. ID do drive: no Graph Explorer, com a conta da Connect, `GET https://graph.microsoft.com/v1.0/users/<e-mail>/drive`
   e copie o `id`.
5. No `.env` do servidor (mantenha `DOCUMENT_STORAGE=local` até o passo 6): `ONEDRIVE_TENANT_ID`, `ONEDRIVE_CLIENT_ID`,
   `ONEDRIVE_CLIENT_SECRET`, `ONEDRIVE_DRIVE_ID` e `ONEDRIVE_FOLDER=ConnectERP`. Depois da troca, rode
   `php service/commands/deploy-check.php`: faltando chave, ele acusa e os arquivos continuam no servidor; com as chaves
   completas, ele também confere autorização, permissão e drive no OneDrive (ADR 0378).
6. Antes de trocar para `onedrive`, copie os arquivos que já estão no servidor (ADR 0376), com as chaves do OneDrive
   já no `.env` e `DOCUMENT_STORAGE=local`:
   `php tools/hosting/onedrive-copy.php --dry-run` (conta; já confere a ligação) e depois `php tools/hosting/onedrive-copy.php`
   (envia; se algum falhar, rode de novo: só o que faltou sobe). Com tudo copiado, troque para `DOCUMENT_STORAGE=onedrive`. A pasta
   local não é apagada. A cópia de leitura fica em `storage/cache/onedrive/` e a faxina diária apaga o que ficou 7 dias
   sem uso (ADR 0375).
