# 0382 — DNS de e-mail no deploy-check

- Status: Aceito
- Data: 17/11/2026
- Contexto: continua o ADR 0381 (conferência do e-mail de produção).

## Contexto
O `tools/hosting/email-teste.php` (ADR 0381) só roda quando alguém lembra. Se o SPF, o DKIM ou o DMARC do domínio
forem apagados ou mudarem (troca de provedor de e-mail, edição no DNS), os boletos passam a cair no spam sem aviso.

## Decisão
- `MailDns::deployWarnings(env, resolvedor)`: com `ERP_MAIL_DELIVERY=production`, confere o domínio de
  `MOVESOS_SMTP_FROM_ADDRESS` e devolve um aviso por item que não está ok. Remetente vazio ou inválido também é aviso.
- Nova chave `MAIL_DKIM_SELECTOR` no `.env` (lista do `Config.php` e modelo `.env.production.example`): o seletor DKIM
  do hPanel. O `email-teste.php` usa essa chave quando `--dkim-seletor` não é passado.
- No `deploy-check` é **sempre aviso, nunca erro**: o DNS pode demorar a propagar, e um erro faria o `update.sh`
  (ADR 0361) voltar a versão sem motivo.

## Consequências
- Toda atualização na hospedagem mostra se o DNS de e-mail continua certo.
- Envio desligado não consulta o DNS.
- Testes: `MailDnsTest` (9 testes) e `EnvironmentKeysTest` (chave nova na lista e no modelo).
