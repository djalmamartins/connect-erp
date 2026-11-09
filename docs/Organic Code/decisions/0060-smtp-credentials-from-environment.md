# 0060 — Credenciais SMTP pelo ambiente, valendo sobre a tabela settings

- Status: aceito (09/11/2026)
- Contexto: fila de envio de notificações (ADR 0055); revisão de segurança da API (`docs/security/REVIEW_API_FINANCEIRO.md`)

## Contexto

`Source\Support\Email` monta o PHPMailer a partir das constantes `CONF_MAIL_*`, que o
`source/Boot/Settings.php` preenchia direto da tabela `settings` (`mail_host`, `mail_user`,
`mail_pass` etc.). Isso deixa a senha SMTP no banco, ao alcance de dumps, backups e de qualquer
tela que leia a configuração. A regra do projeto é segredo fora do Git e fora do banco (`.env` local ou cofre).

## Decisão

- `Source\Support\MailConfig` resolve a configuração SMTP. Para cada campo, uma variável de
  ambiente `MOVESOS_SMTP_*` (HOST, PORT, USER, PASS, SECURE, AUTH, FROM_ADDRESS, FROM_NAME) **vale
  sobre** a coluna legada de `settings`. Variável vazia ou ausente cai no valor legado.
- `Settings.php` define as constantes `CONF_MAIL_*` a partir do `MailConfig`, e o `Email`
  continua igual. Nenhum chamador muda.
- `Config.php` passa a carregar as `MOVESOS_SMTP_*` do `.env`. O `.env.example` traz as chaves vazias.
- A tabela `settings` não é alterada nem limpa aqui, porque apagar `mail_pass` do banco é uma
  operação em produção. Isso fica como passo de implantação: configurar `MOVESOS_SMTP_*` no
  servidor, conferir um envio no sandbox e só então zerar `mail_pass` no banco.

## Consequências

- Antes de ligar o modo produção, o servidor precisa ter `MOVESOS_SMTP_*` definidas. Sem elas, o
  sistema ainda funciona com os valores legados, mas a senha continua no banco.
- O valor de `MOVESOS_SMTP_AUTH` é lido como booleano (`true`/`false`/`1`/`0`/`on`/`off`).
- Teste: `tests/legacy/Unit/MailConfigTest.php`.
