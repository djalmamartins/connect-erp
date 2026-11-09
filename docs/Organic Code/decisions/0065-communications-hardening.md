# 0065 — Comunicação: assunto em uma linha, unidades com teto, trava e limite diário

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_COMUNICACAO.md`

## Contexto

A revisão de segurança da comunicação pela outbox (ADR 0059) conferiu opt-in, sandbox, frequência e
injeção no modelo. O opt-in, o sandbox e o escape do modelo estão certos. Ficaram quatro pontos.

## Decisão

- **Assunto em uma linha.** `Announcements::line()` troca quebras de linha (CR, LF, U+2028 e U+2029)
  por espaço no assunto e no motivo do cancelamento. O despachante faz o mesmo ao montar o assunto
  do e-mail, para o que já estiver gravado. O PHPMailer já limpa o cabeçalho, mas o assunto não
  depende só disso.
- **Unidades escolhidas.** `Announcements::unitIds()` valida o comunicado e o aviso de cobrança da
  mesma forma: inteiros positivos, sem repetição, de 1 a `MAX_UNITS` (2.000). Antes, o aviso de
  cobrança aceitava qualquer lista sem teto e fazia `in_array` para cada unidade em atraso.
- **Trava por condomínio.** Criar um comunicado ou um aviso trava a linha do condomínio
  (`SELECT ... FOR UPDATE`) até o fim da transação. Os avisos recentes são lidos depois da trava, e
  dois pedidos de aviso de cobrança ao mesmo tempo não avisam a mesma unidade duas vezes na janela
  de 7 dias.
- **Limite diário.** Até `MAX_PER_DAY` (20) comunicados e avisos criados por condomínio em 24 horas,
  contados sob a mesma trava. Passou disso, a API responde 409 (`announcement_conflict`). O número
  pode ser ajustado na issue #46.

## Fora deste ADR

- Limite de requisições por usuário na API, que continua pendente para toda a API.
- Descadastro por link no e-mail: a recusa segue pelo cadastro de pessoas, pela administração.

## Consequências

- `AnnouncementsTest` e `AnnouncementsIntegrationTest` cobrem o assunto em uma linha, as unidades
  e o limite diário.
