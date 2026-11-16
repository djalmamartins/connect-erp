# 0325 — Produção na hospedagem compartilhada: cron no lugar do agendador e backup sem Docker para o OneDrive

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 (produção no Cloud Startup, backup no OneDrive, "tome as melhores decisões")
- Relacionadas: ADR 0012 (banco único), 0013/0055 (agendador e fila de avisos), 0156 (consulta dos boletos), 0315 (lembretes), `docs/ROADMAP_V1.md` etapas 4 e 5

## Contexto

A produção vai rodar no plano Cloud Startup (4 núcleos, 4 GB de RAM, 100 GB, 2 milhões de inodes), domínio
`connectcondominios.com.br`. Não há Docker nem processo rodando o tempo todo. O laboratório depende de dois pontos
que não existem lá: o laço `service/jobs/scheduler.sh` (serviço `scheduler` do compose) e o `tools/backup-database.sh`,
que usa `docker compose exec`.

## Decisão

1. `tools/hosting/cron.sh minuto|hora|dia|backup` roda as mesmas rotinas do `scheduler.sh`, uma linha de cron por grupo,
   com trava `flock` por grupo (rodada anterior ainda ativa = sai sem fazer nada), log em `storage/logs/cron-<grupo>.log`
   cortado em 2000 linhas e falha de uma rotina sem parar as outras. O envio da `mail_queue` continua opcional
   (`MAIL_QUEUE_SEND=1`).
2. `tools/hosting/backup.sh` gera o dump do banco (`mariadb-dump`/`mysqldump`, `--single-transaction`) e o pacote de
   `storage/private/{documents,imports}`. A senha vai num arquivo `[client]` 0600 temporário gerado por
   `tools/hosting/db-client-config.php` a partir do mesmo `source/Boot/Config.php` do sistema, nunca na linha de comando.
3. Destino fora da hospedagem: o rclone, num remote `crypt` por cima do OneDrive empresarial da Connect
   (`BACKUP_RCLONE_REMOTE`). A Microsoft só recebe arquivos cifrados. Diário por 30 dias e, no dia 1, cópia mensal por
   400 dias. Localmente ficam 3 dias, para não ocupar o disco do plano.
4. Os scripts do laboratório (`scheduler.sh`, `backup-database.sh`, `backup-files.sh`) continuam iguais.

## Consequências

- A senha da cifragem do rclone é a chave do backup: sem ela não há restauração. Ela fica no `rclone.conf` do servidor e
  numa cópia guardada pelo Djalma, fora do Git e do chat.
- Teste de restauração é obrigatório antes do piloto (etapa 5 do roadmap): baixar um diário, abrir com o rclone, importar
  num banco vazio e conferir os arquivos.
- PENDENTE: o rclone e o `mariadb-dump` precisam existir na hospedagem; conferir no primeiro acesso por SSH
  (o rclone é um binário só e pode ficar em `~/bin`, apontado por `RCLONE_BIN`).
