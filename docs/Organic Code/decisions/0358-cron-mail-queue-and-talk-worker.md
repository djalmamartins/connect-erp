# ADR 0358 — Fila de e-mails e worker do Talk no cron da hospedagem

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0055 (fila de avisos), ADR 0325 (cron na hospedagem), ADR 0349 (worker do Talk), ADR 0357 (.env)

## Contexto

O cron do hPanel roda os comandos sem carregar o `.env`. O `tools/hosting/cron.sh` só mandava a `mail_queue` para o
SMTP com `MAIL_QUEUE_SEND=1` no ambiente do cron, que ninguém configura no hPanel: na hospedagem, os avisos e boletos por
e-mail seriam gerados e ficariam parados na fila. O `PHP_BIN` também só vinha do ambiente.

O ADR 0349 decidiu rodar `tools/talk-worker.php` pelo cron a cada minuto, mas a linha não estava no roteiro da
hospedagem (HOSPEDAGEM.md §4): o Talk receberia mensagens e não enviaria nenhuma.

## Decisão

- O `cron.sh` lê do `.env`, quando não estão no ambiente, `MAIL_QUEUE_SEND`, `ERP_MAIL_DELIVERY` e `PHP_BIN` (o mesmo
  jeito do `backup.sh`). O ambiente continua valendo sobre o `.env`.
- A fila sai pelo SMTP com `MAIL_QUEUE_SEND=1` ou com `ERP_MAIL_DELIVERY=production` (a mesma chave que já faz o ERP
  enfileirar para o morador). No laboratório (`log`/`sandbox`) nada muda.
- HOSPEDAGEM.md §4 ganha a linha `* * * * * php .../tools/talk-worker.php`.

## Testes

- `HostingCronEnvTest`: roda o `cron.sh` de verdade numa pasta temporária com um `php` falso: `PHP_BIN` vem do `.env`;
  `log` não envia; `production` e `MAIL_QUEUE_SEND="1"` enviam; o ambiente vale sobre o `.env`; o roteiro tem o worker do
  Talk. Com o `cron.sh` anterior o teste falha (conferido).
