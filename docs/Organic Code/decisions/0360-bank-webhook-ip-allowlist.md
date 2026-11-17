# ADR 0360 — Lista de IPs do webhook dos bancos na hospedagem

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0023 (IP do cliente), ADR 0156 (webhook de cobrança), ADR 0322 (Cora), ADR 0325 (hospedagem),
  ADR 0357 (.env de produção)

## Contexto

O ADR 0156 protege o webhook dos bancos com o segredo do endereço, a consulta de cada título no banco antes da baixa e,
no servidor, mTLS e lista de IPs no proxy. Na hospedagem Cloud Startup não há proxy próprio: o ROADMAP §3.9 e o
HOSPEDAGEM.md §7 diziam que a lista de IPs iria no `.htaccess`, mas nada fazia isso, e o `.htaccess` não sabe ler a
cadeia do `X-Forwarded-For` como o `ClientIp` (ADR 0023).

## Decisão

- Nova chave opcional `BANK_WEBHOOK_ALLOWED_IPS` (IPs ou faixas CIDR, IPv4 e IPv6, separados por vírgula), na lista do
  `Config.php` e no `.env.production.example`, vazia.
- `ClientIp::allowed(ip, lista)` reaproveita a comparação de faixas do `ClientIp`. Lista vazia libera todos; IP inválido
  ou desconhecido só passa com a lista vazia.
- `BankWebhook::receive` confere o IP (`ClientIp::resolve()`) antes de ler o corpo e de chamar `BankWebhooks`. Fora da
  lista: 404 igual ao do segredo errado (não revela que a rota existe) e aviso `bank_webhook_ip_blocked` no log.
- Não inventamos os IPs dos bancos: o Djalma preenche com o que o Inter e a Cora informarem. Sem lista, o comportamento
  é o de hoje.

## Testes

- `ClientIpTest`: IPs e CIDR (v4 e v6), lista vazia, IP nulo, entradas inválidas; a conferência no webhook vem antes de
  ler o corpo e de chamar o serviço (falha com o controlador anterior, conferido); IP fora da lista recebe 404.
- `EnvironmentKeysTest`: a chave nova está no `Config.php` e no modelo.

## Riscos

- Se o banco mudar os IPs sem avisar, as notificações passam a receber 404 e o banco reenvia; a consulta de hora em hora
  (`cron.sh hora`) continua dando a baixa.
