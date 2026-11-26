# 0656 — Caixa de teste para toda a fila de e-mail no lab e diagnóstico do envio

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 17:57: configurou no `.env` do lab `ERP_MAIL_ELIVERY=sandbox`,
  `ERP_MAIL_SANDBOX_TO=<caixa dele>` e `LAB_MAIL_QUEUE_SEND=1`, mas os e-mails de teste não chegam e o
  envio dá erro.

## Contexto

- O nome da chave estava errado (`ERP_MAIL_ELIVERY`). O `Config.php` só lê chaves conhecidas, então a linha
  era ignorada em silêncio e o app ficava no modo `log`.
- O compose só passa `ERP_MAIL_SANDBOX_TO` e `LAB_MAIL_QUEUE_SEND` ao scheduler quando o container é criado;
  mudar o `.env` não chega a um container já rodando.
- O modo `sandbox` só valia para os avisos do ERP (ADR 0055). O resto da `mail_queue` (chamados, propostas,
  código de 2FA, convites, compromissos do Studio) saía para o endereço gravado, inclusive no lab, ao fim de
  cada página (ADR 0611) e no scheduler com `LAB_MAIL_QUEUE_SEND=1`.
- `Email::send` só limpava os destinatários quando o envio dava certo. Na fila, que reaproveita o mesmo
  PHPMailer, o destinatário de um envio que falhou seguia junto no e-mail seguinte, para outra pessoa.
- O erro do SMTP fica em `mail_queue.error_message` e no log do banco, sem um jeito simples de ver.

## Decisão

- `Source\Support\MailSandbox`: fora da produção, com `ERP_MAIL_DELIVERY=sandbox` e `ERP_MAIL_SANDBOX_TO`
  válido, `Email::sendQueue` manda **todo** e-mail da fila só para a caixa de teste, com `[TESTE]` no assunto
  (uma vez só). Em produção nada muda.
- `Email::send` limpa destinatários e anexos num `finally`, também quando o envio falha.
- `tools/email-diagnostico.php [--enviar]` (só leitura, sem senha): modo, caixa de teste, chaves do `.env`
  com nome parecido com uma chave de e-mail (ex.: `ERP_MAIL_ELIVERY` → `ERP_MAIL_DELIVERY`), SMTP em uso e
  de onde vem cada campo (ambiente, Studio ou senha que não abre), porta × segurança, contagem e últimos erros
  da fila; com `--enviar`, um e-mail direto para a caixa de teste com o erro exato do servidor.
- Sem migration, sem mudança de API ou de permissões.

## Consequências

No lab, ligar o envio nunca manda e-mail para um endereço real gravado na fila, desde que o modo seja
`sandbox` com a caixa de teste. Sem a caixa de teste, o comportamento é o de antes. Testes:
`tests/legacy/Unit/MailSandboxTest.php`.

## Ajuste (26/11/2026, 19:05)

Djalma pediu a recuperação de senha de `equipe.teste@connect.lab` e nada chegou: a recuperação de senha e o
e-mail de teste do Studio não passam pela fila, chamam `Email::send` direto, e iam para o endereço de mentira.
O redirecionamento para a caixa de teste saiu do `sendQueue` e foi para o `Email::send`, que cobre a fila e o
envio direto. O endereço gravado na `mail_queue` continua o real (histórico).
