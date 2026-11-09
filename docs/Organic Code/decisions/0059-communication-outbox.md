# 0059 — Comunicação pela outbox: comunicados, aviso de cobrança, agendados e endereços

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

O catálogo tem Comunicados Gerais (AP-111) e, em Comunicação, Cobrança (AP-137), Agendada (AP-138) e
Endereços (AP-139). As fichas são esqueletos. A outbox (ADR 0054) e a fila (ADR 0055) já entregam
avisos por unidade com modelo do container send, e fora de produção o envio vai só para log ou para
a caixa de teste. A coordenação pediu a comunicação por esse caminho, só em log ou sandbox.

## Decisão

- **Uma origem:** `erp_announcements` (migration 20261108_0537), com `kind` general (comunicado) ou
  collection (aviso de cobrança). **Cada unidade vira um evento na outbox**, com
  `subject_table = erp_announcements`. Nada é enviado direto: a fila entrega pelas regras do ADR 0055,
  e fora de produção nenhum e-mail vai para morador.
- **AP-111 Comunicado geral** (`POST /api/v1/announcements`):
  - assunto de 5 a 120 caracteres e texto de 10 a 5.000, limpos de caracteres de controle e
    escapados no modelo `announcement`;
  - público: todas as unidades ativas ou as escolhidas (até 2.000);
  - recebem as pessoas ativas da unidade com e-mail válido que **não recusaram** comunicado. A recusa
    é o campo novo `notices_opt_out` no cadastro de pessoas (padrão 0 = recebe, como antes);
  - o assunto e o texto são lidos do comunicado na hora do envio. Comunicado cancelado não sai.
- **AP-137 Aviso de cobrança** (`POST /api/v1/announcements/collection`):
  - é para as unidades com conta vencida na data de referência (`Agreements::delinquency`), com
    atraso mínimo configurável (padrão 1 dia);
  - só vai para quem autorizou cobrança por e-mail (`billing_by_email`, como no ADR 0056);
  - o modelo `collection-reminder` traz a quantidade de contas, o valor sem encargos e o vencimento
    mais antigo;
  - **antirrepetição:** unidade avisada (aviso ativo, não cancelado) nos últimos 7 dias fica de fora e
    é contada em `skipped_recent`. Sem ninguém no critério, recusa;
  - exige também `erp.agreements.view`.
- **AP-138 Agendada:**
  - `send_at` vazio é agora; agendado, vai até 60 dias à frente e vira o `available_at` dos eventos
    (`NotificationOutbox::record` ganhou esse parâmetro). A fila só pega o evento quando chega a hora;
  - `GET /announcements?scheduled=1` lista os que ainda vão sair;
  - `POST /announcements/{id}/cancel` (com motivo) cancela o comunicado e os avisos pendentes. Os já
    enviados ficam como estão.
- **AP-139 Endereços** (`GET /api/v1/communication-addresses`):
  - mostra, por unidade ativa, as pessoas com o e-mail mascarado e se recebem comunicado e cobrança;
  - os totais mostram as unidades sem ninguém para comunicado e sem ninguém para cobrança;
  - a correção é feita no cadastro de pessoas; exige também `erp.residents.view`.
- **Situação da entrega:** cada comunicado mostra quantos avisos estão pendentes, enviados, com falha
  ou cancelados, e o total de destinatários, a partir da outbox.
- **Permissões:** `erp.announcements.view` e `erp.announcements.manage`. Escopo por condomínio;
  tudo auditado.

## Fora deste ADR

- SMS, WhatsApp ou aplicativo: só e-mail pela fila.
- Anexo no comunicado, que poderia vir do Disco Virtual depois.
- Envio real: segue a pendência do ADR 0055 e a configuração explícita de produção (ADR 0060).

## Consequências

- A tela da fila de avisos (layout) ganhou os rótulos "Comunicado" e "Aviso de cobrança".
- Telas (layout): AP-111, AP-137, AP-138 e AP-139 usam estas rotas. O campo "Não quer receber
  comunicados por e-mail" aparece no cadastro de pessoas.
