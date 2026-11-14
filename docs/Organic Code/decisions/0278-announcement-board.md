<!-- markdownlint-disable MD024 -->
# 0278 — Situação dos comunicados

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0054 (outbox de avisos);
  - 0059 (comunicação pela outbox: comunicado geral, aviso de cobrança e agendamento, AP-111, AP-137 e AP-138).

## Contexto

Os comunicados gerais e os avisos de cobrança ficam em `erp_announcements` e saem pela outbox (ADR 0059). A lista
mostra até 200, um a um, com os envios de cada um.

A administradora não vê, de uma vez:

- quantos comunicados já saíram, quantos estão agendados e quantos foram cancelados;
- há quanto tempo os publicados saíram;
- quantos envios falharam e em quais comunicados.

Busquei "mural", "aviso", "comunicado" e "notice" em `source/`, nas migrations, nas definições dos cadastros e no README
das ADRs.

- **Mural:** não existe cadastro com esse nome.
- **Comunicados:** o cadastro existente é `erp_announcements`.
- **Banners (AP-156):** têm início e fim, mas são configuração, podem valer para todos os condomínios e exigem data de
  fim. Não entram aqui.

O cadastro de comunicados não tem data de fim nem arquivamento: o comunicado sai numa data (`send_at`) e, depois, só pode
ser cancelado. Não há "vencidos" nem "sem data de fim" a contar. O cancelado é o que sai do ar. Não criei coluna nem
migration para isso.

## Decisão

Rota nova: `GET /api/v1/reports/notice-board?condominium_id=`, logo depois de `POST /documents/restore-linked`. O
service é `AnnouncementBoard`, em `Source\Services\Erp`.

### Reuso

- **Tipos e estados do cadastro:** `KINDS` repete a enum `kind` de `erp_announcements`; publicado, agendado e cancelado
  seguem a regra da `Announcements::list` (ativo e `send_at` já passou, ou ainda não).
- **Envios:** `DELIVERY` repete a enum `status` de `erp_notification_outbox`. Os eventos são contados pelo
  `subject_table` `erp_announcements`, como na `Announcements::stats`.
- **Teste de guarda:** o teste unit confere que `KINDS` e `DELIVERY` batem com as enums das migrations. Uma mudança na
  enum quebra o teste antes de sumir do relatório.

### Regra pura `summarize()`

- **`rows`:** uma linha por tipo, sempre todos os de `KINDS`, com `announcements`, `published`, `scheduled` e
  `cancelled`, que somam `announcements`. `totals` soma as linhas.
- **`by_age`:** idade dos publicados, em dias desde o envio, nas faixas de `AGES` (até 30, de 31 a 90, mais de 90). A
  soma é `totals.published`.
- **`delivery`:** eventos da outbox dos publicados por estado de `DELIVERY`, e `failed_ids` (comunicados com algum envio
  com falha, até 200, com `failed_truncated`).
- **`scheduled_ids`:** os agendados, do mais próximo ao mais distante (até 200, com `scheduled_truncated`).

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.announcements.view`, as mesmas da lista de comunicados. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** tipos, contagens, ids e a data de referência. Não saem assunto, texto, unidade, destinatário ou
  e-mail.
- **Não toca** o portal do morador, o e-mail, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\AnnouncementBoardTest`:**
  - as constantes batem com as enums das migrations;
  - as faixas de idade vindas de `AGES`, nos limites;
  - publicados, agendados e cancelados por tipo, com totais iguais à soma das linhas;
  - os envios só dos publicados; o cancelado, o outro condomínio e outra origem da outbox ficam de fora;
  - os ids dos agendados, em ordem, e dos com falha, e o corte em 200;
  - nenhum assunto nem texto;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\AnnouncementBoardTest`** (MariaDB, banco `*_test`):
  - comunicados e eventos da outbox, com um cancelamento de verdade (`Announcements::cancel`);
  - somente leitura, sem assunto nem texto;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela de comunicados pode ganhar o quadro; quando
  ganhar, vem o artigo.
