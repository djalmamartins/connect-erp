<!-- markdownlint-disable MD024 -->
# 0232 — Resumo dos achados e perdidos

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0042 (portaria: AP-134 achados e perdidos), só como fonte dos dados;
  - 0230 (movimento da portaria) e 0228 (mesmo padrão de relatório: regra pura, totais iguais à soma das linhas).

## Contexto

O cadastro de achados e perdidos (AP-134) lista item por item, com quem achou e a quem foi devolvido. Nenhum relatório
resume a guarda: busquei `erp_lost_found` em `source/`, no README das ADRs e nos handoffs 02xx, e só o cadastro e os
dados de exemplo usam a tabela. O síndico e a administradora querem saber quantos itens foram achados no período,
quantos voltaram ao dono e em quanto tempo, quantos foram descartados e há quanto tempo os guardados estão na portaria,
para decidir doação ou descarte conforme o regimento.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/lost-found?condominium_id=&from=&to=`. O service é
`LostFoundSummary`, em `Source\Services\Erp`.

### Reuso

- **Situações:** as do cadastro (`RegistryService::definition('lost_found')`: Guardado, Devolvido, Descartado).
- **Regra das listas:** sem os excluídos.
- **Período:** padrão do mês de `to`, até 366 dias, como nos relatórios 0226, 0228 e 0230.

### Regra pura `summarize()`

- **rows:** uma linha por situação do cadastro (sempre as três), com os itens achados no período (`found_on` de `from` a
  `to`); `totals.items` é a soma das linhas.
- **returns:** dos devolvidos do período, `dated` (com data de devolução não anterior à de achado), `days_total`,
  `avg_days` (total ÷ dated, 1 casa) e `max_days`. Devolvido sem data, ou com data antes do achado, conta em `count` e
  fica fora do tempo.
- **custody:** os guardados hoje que foram achados até `to` (de qualquer período), pela idade em `to`: até 30 dias, 31 a
  90, 91 a 180 e mais de 180; `total` é a soma das faixas.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.lost_found.view`, a mesma do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** só as situações e as contagens. A consulta nem lê item, local, quem achou, a quem foi devolvido ou
  observação.
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\LostFoundSummaryTest`:**
  - a regra pura: as três situações, o tempo de devolução (com a devolução sem data e a anterior ao achado), as faixas de
    idade com as bordas e os totais;
  - a montagem no SQLite: os guardados de antes do período só na idade, sem os excluídos, os de depois e os de outro
    condomínio, e sem dado do item;
  - validação, escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\LostFoundSummaryTest`** (MariaDB, banco `*_test`): itens gravados pelo cadastro, as linhas, o tempo de
  devolução, a idade dos guardados, nada na auditoria, sem dado do item e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Achados e perdidos"; quando ganhar, vem o artigo da
  Central de Ajuda.
