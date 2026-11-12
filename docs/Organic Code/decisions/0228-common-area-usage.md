<!-- markdownlint-disable MD024 -->
# 0228 — Uso dos espaços comuns

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-113 (reservas das áreas comuns) e AP-008 (cadastro das áreas comuns), só como fonte dos dados;
  - 0221 (baixa das reservas que passaram sem aprovação);
  - 0226 (mesmo padrão de relatório: regra pura, totais iguais à soma das linhas).

## Contexto

O relatório de reservas (`RegistryReports::reservations`) lista reserva por reserva, com morador e unidade. O resumo
operacional (`OperationsSummary`) conta só as confirmadas do mês. O síndico e o conselho querem saber, por área e
período, quanto cada espaço foi usado, quantos pedidos ficaram esperando ou foram cancelados, quanto entrou de taxa e em
que dias da semana o uso se concentra, para revisar regras, taxas e horários das áreas.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/common-area-usage?condominium_id=&from=&to=`. O service é
`CommonAreaUsage`, em `Source\Services\Erp`.

### Reuso

- **Rótulos:** os do cadastro (`Definitions`): o modo de reserva das áreas comuns e a situação das reservas.
- **Regras do relatório de reservas:** sem as excluídas; a taxa só conta nas confirmadas.
- **Período:** padrão do mês de `to`, até 366 dias, como no relatório de tempo de atendimento (0226).

### Regra pura `summarize()`

Uma linha por área: as ativas, mesmo sem reserva, e as inativas que tiveram reserva no período.

- **reservations** = confirmed + pending (aguardando aprovação) + cancelled.
- **fee_cents:** a taxa das confirmadas.
- **days_used:** dias distintos com reserva confirmada; **occupancy_pct** = days_used ÷ dias do período (1 casa).
- **units:** quantas unidades distintas usaram a área (só a contagem).
- **cancel_pct** = cancelled ÷ reservations (1 casa).
- **by_weekday:** as confirmadas por dia da semana (seg a dom), somando `confirmed`.
- **Ordem:** mais confirmadas primeiro, depois o nome.
- **Totais:** a soma das linhas (`days_used` vira dias-área); o `cancel_pct` do topo sai das somas. As unidades
  distintas não vão ao topo, porque não somam entre áreas.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.reservations.view`, a mesma do relatório de reservas; sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** o nome e o modo da área e as somas. Não saem morador, unidade, horário ou observação.
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\CommonAreaUsageTest`:**
  - a regra pura: situações, taxa só das confirmadas, dias e unidades distintos, dia da semana, área ativa sem reserva,
    inativa com e sem reserva, totais iguais à soma das linhas;
  - a montagem no SQLite: sem as excluídas, fora do período e de outro condomínio, e sem dado pessoal;
  - validação, escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\CommonAreaUsageTest`** (MariaDB, banco `*_test`): reservas nas unidades do cadastro, a linha da área e a
  área sem reserva, os totais, nada na auditoria, sem o nome do morador e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Uso dos espaços comuns"; quando ganhar, vem o artigo
  da Central de Ajuda.
