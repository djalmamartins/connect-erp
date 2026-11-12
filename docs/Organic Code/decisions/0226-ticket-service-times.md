<!-- markdownlint-disable MD024 -->
# 0226 — Tempo de atendimento dos chamados do condomínio

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0142 (conversa do chamado);
  - 0153 (resumo da fila do Help Desk);
  - 0213 (ocorrência e chamado);
  - 0224 (totais iguais à soma das linhas).

## Contexto

O Help Desk resume a fila inteira para o Studio (`TicketService::queueSummary`), e o ERP lista os chamados um a um
(`TicketService::listFor`). Nada mostra, por condomínio e período:

- quantos chamados abriram;
- quantos foram resolvidos no prazo;
- quanto tempo levaram.

O síndico e o conselho pedem esses números na prestação de contas do serviço da administradora.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/ticket-service-times?condominium_id=&from=&to=`. O service é
`TicketServiceTimes`.

### Reuso

- **Prazo de cada prioridade:** `TicketService::slaHours` (urgente 24 h, alta 48 h, média 72 h, baixa 120 h).
- **Rótulos de área e prioridade:** `TicketService::AREA_LABELS` e `PRIORITY_LABELS`.
- **Concluído:** as situações do chamado resolvido e fechado.

### Regra pura `summarize()`

O resumo é feito por área (Geral, Técnico, Financeiro) e prioridade. A "categoria" livre do chamado fica de fora: é texto
digitado e pode trazer dado pessoal.

| Campo | O que conta |
|---|---|
| `created` | chamados abertos no período |
| `done` | resolvidos ou fechados |
| `open` | os demais |
| `timed` | concluídos com data de resolução |
| `on_time` / `late` | resolvidos até ou depois do prazo (`due_at`) |
| `overdue_open` | em aberto com o prazo vencido no fim do período |
| `resolution_minutes_total` | minutos somados até a resolução |
| `avg_resolution_hours` | `resolution_minutes_total` ÷ `timed`, com 1 casa |
| `on_time_pct` | `on_time` ÷ `timed`, com 1 casa |

- **Totais do topo:** são a soma das linhas. A média e o percentual do topo saem das somas (minutos inteiros, sem perda
  de arredondamento).
- **Período:** pela data de abertura; padrão do primeiro dia do mês de `to` até `to` (hoje); até 366 dias.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access`, a mesma da lista de chamados do ERP (`Api\V1\Tickets`); sem ela, 403
  (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados:** saem só os grupos e as somas. Não saem protocolo, assunto, mensagem, solicitante, unidade ou responsável.
- **Limite:** até 20000 chamados no período.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\TicketServiceTimesTest`:**
  - a regra pura: no prazo, atrasado, concluído sem data, vencido em aberto, em aberto no prazo, prazo por prioridade,
    ordem e lista vazia;
  - os totais iguais à soma das linhas;
  - a montagem no SQLite, sem outro condomínio, sem chamado de antes do período e sem dado do chamado;
  - validação e escopo (404);
  - contrato;
  - rota.
- **`Integration\TicketServiceTimesTest`** (MariaDB, banco `*_test`):
  - chamados abertos pelo `TicketService::open`, com a situação e as datas do atendimento;
  - linhas e totais, com a média e o percentual;
  - o chamado de fora do período;
  - nenhum dado do chamado no JSON;
  - nenhuma linha nova na auditoria;
  - os chamados sem mudança;
  - escopo.
- **Sem tela nova neste patch.** A lista de chamados do ERP pode ganhar o resumo "Atendimento"; quando ganhar, vem o
  artigo da Central de Ajuda.
