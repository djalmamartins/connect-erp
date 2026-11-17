# 0349 — WhatsApp do Talk num Raspberry Pi e worker do Talk pelo cron

- **Status:** aceito (etapa 2 do Talk dentro do ERP)
- **Data:** 2026-11-17
- **Relacionados:** 0324 (Talk dentro do ERP), 0308 (API do Talk separado)

## Contexto

A produção da v1.0 roda no Cloud Startup Hosting: sem Docker e sem processo contínuo, só com o cron do hPanel.
O Talk dentro do ERP (0324) precisa de duas coisas que o Talk separado tinha no Docker:
- o motor do WhatsApp por QR Code (Evolution API, Node, com um Postgres próprio), ligado o tempo todo;
- o worker que envia a fila, baixa a mídia, faz a triagem da Jack, os agendamentos e as campanhas.

Em 16/11, a Evolution não rodava em produção em lugar nenhum, só no Docker do laboratório. O Djalma escolheu
um Raspberry Pi 4B de 4 GB na casa dele, em vez de uma VPS ou da API oficial da Meta.

## Decisão

- **Motor no Pi.** O `tools/whatsapp-pi/` tem o `docker-compose.yml` (Evolution v2.3.6, a versão testada no
  Talk; Postgres 16; cloudflared) e o `instalar.sh`, que é idempotente e não apaga nada nem troca chaves. As
  imagens têm versão arm64. O motor sai para a internet só pelo Cloudflare Tunnel, em
  `whatsapp.<domínio>`, sem porta aberta no roteador. A Evolution só aceita a `EVOLUTION_API_KEY`.
- **ERP aponta para o Pi.** `EVOLUTION_URL=https://whatsapp.<domínio>`. Com o `EVOLUTION_WEBHOOK_URL` vazio,
  a Evolution chama o Talk pelo `TALK_URL` público (`/webhook/{id}` com `X-Talk-Secret`, já existente).
- **Worker pelo cron.** `tools/talk-worker.php` faz o mesmo laço do `bin/talk-worker`, com prazo de 55 s,
  e o cron do hPanel o chama a cada minuto. Uma trava (`storage/talk/worker.lock`) impede dois ao mesmo
  tempo, e a fila já usa SKIP LOCKED. O `--check` lê o mesmo sinal de vida (`worker_heartbeat`) que o
  `/health` do Talk.
- **Saída para VPS.** O mesmo `instalar.sh` roda numa VPS. Para mudar, basta apontar o túnel para lá; o ERP
  não muda.

## Consequências

- Custo mensal zero, mas o WhatsApp para se faltar luz ou internet na casa. As mensagens chegam quando volta,
  porque o WhatsApp guarda e entrega.
- O cartão SD gasta com o Postgres. O recomendado é SSD no USB, e o instalador avisa quando o sistema está no SD.
- Perder o Pi custa só ler o QR Code de novo, porque as conversas ficam no banco do ERP.
- Uma resposta pode demorar até ~1 s com o worker no ar. Entre o fim de um turno e o começo do próximo, pode
  chegar a alguns segundos.
- Depende de o domínio estar na Cloudflare (plano Free) para o túnel com nome próprio.
- Testes: o `talk-worker.php` foi rodado contra o banco de teste (sinal de vida, `--check` e trava contra dois
  ao mesmo tempo). O `instalar.sh` e o compose foram checados quanto à sintaxe. A instalação real no Pi é feita
  pelo Djalma, seguindo o README.
