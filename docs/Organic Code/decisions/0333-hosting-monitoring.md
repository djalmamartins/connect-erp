# 0333 — Monitoramento da hospedagem: sinal de vida do cron, /health e vigia por e-mail

- Status: Aceito
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.6 e etapa 5 (backup e monitoramento); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0325 (cron e backup na hospedagem compartilhada), ADR 0280 (saúde da fila de avisos)

## Contexto

Na Cloud Startup não há processo rodando o tempo todo: as rotinas são linhas de cron (ADR 0325). Se o cron parar, o backup
falhar ou a fila de avisos travar, ninguém fica sabendo. O `/api/v1/health` só olhava o banco.

## Decisão

1. `tools/hosting/cron.sh` grava, ao fim de cada grupo, `storage/temp/heartbeat/<grupo>` com "<epoch> <falhas>".
2. `Source\Support\HostingHealth` (regra pura) diz por grupo `ready`, `failing`, `stale` ou `unknown`, com prazos de 10 min
   (minuto), 2h10 (hora) e 26 h (dia e backup). `unknown` (nunca rodou) não conta como problema, para o lab não alarmar.
3. `/api/v1/health` passa a trazer `checks.cron` e o `status` vira `degraded` (HTTP 200) quando alguma rotina está atrasada ou
   falhando; HTTP 503 continua só para banco fora. Saem só as palavras, sem idade, caminho nem erro (rota pública).
4. `tools/hosting/watchdog.php` roda no grupo `hora` e manda e-mail para `ALERT_EMAIL` (regra pura `HostingWatchdog`):
   rotina atrasada ou com falha, avisos da fila com falha nas últimas 24 h e banco sem resposta. Avisa quando as chaves dos
   problemas mudam, repete a cada 12 h e avisa uma vez quando volta ao normal.
5. O cron parado de vez é coberto por um monitor externo gratuito olhando a palavra-chave `"status":"ready"` no `/health`
   (roteiro em `docs/process/HOSPEDAGEM.md` §8).

## Consequências

- Pendente do Djalma: escolher o e-mail do `ALERT_EMAIL` e criar a conta do monitor externo (5 minutos, gratuito).
- Um monitor que só olha o código HTTP não vê o `degraded`; precisa ser do tipo palavra-chave.
- A consulta dos boletos (grupo `hora`) falhando aparece como `failing`; o detalhe fica em `storage/logs/cron-hora.log`.
