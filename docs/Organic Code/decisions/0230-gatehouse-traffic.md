<!-- markdownlint-disable MD024 -->
# 0230 — Movimento da portaria

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0042 (portaria: AP-131 a AP-136), só como fonte dos dados;
  - 0228 e 0226 (mesmo padrão de relatório: regra pura, totais iguais à soma das linhas).

## Contexto

O registro de acessos (AP-133) e a previsão de visita (AP-132) listam lançamento por lançamento, com nome e documento.
Nenhum relatório resume o movimento da portaria: busquei `erp_access_logs` e `erp_visit_forecasts` em `source/` e só os
cadastros os usam. O síndico e a administradora querem saber, por período, quantas pessoas entraram de cada tipo, em que
faixa do dia, quanto tempo ficaram, quantas saídas ficaram sem registro e quantas visitas previstas aconteceram, para
dimensionar a portaria e cobrar o preenchimento dos registros.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/gatehouse-traffic?condominium_id=&from=&to=`. O service é
`GatehouseTraffic`, em `Source\Services\Erp`.

### Reuso

- **Tipos e situações:** os do cadastro (`RegistryService::definition`: `access_logs` e `visit_forecasts`).
- **Regras das listas:** sem os excluídos; acesso cancelado fica de fora.
- **Período:** padrão do mês de `to`, até 366 dias, como nos relatórios 0226 e 0228.

### Regra pura `summarize()`

Uma linha por tipo do cadastro (visitante, prestador, entregador, morador), sempre as quatro:

- **entries**; **with_vehicle** (com placa); **with_unit** (com unidade); **from_forecast** (ligado a uma visita
  prevista);
- **exited** (com hora de saída) e **no_exit** = entries − exited;
- **timed:** saídas no mesmo dia (saída ≥ entrada); **stay_minutes_total** e **avg_stay_minutes** (total ÷ timed,
  inteiro);
- **by_band:** entradas por faixa do dia (madrugada 0–6 h, manhã 6–12 h, tarde 12–18 h, noite 18–24 h), somando entries.

Os totais do topo são a soma das linhas; a média do topo sai das somas.

As visitas previstas no período saem à parte: **total** = done + cancelled + open, e **overdue** = as em aberto com a
data (ou o fim, `expected_until`) já passada.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.access_logs.view` e `erp.visit_forecasts.view`, as mesmas do registro de
  acessos e da previsão de visita; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** só os tipos e as contagens. A consulta já traz placa, unidade e visita como verdadeiro ou falso; não
  saem nome, documento, placa, unidade, portaria ou observação.
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\GatehouseTrafficTest`:**
  - a regra pura: os quatro tipos, as faixas do dia (com as bordas), a permanência, a saída antes da entrada, a saída sem
    registro, as visitas previstas e os totais iguais à soma das linhas;
  - a montagem no SQLite: sem os cancelados, os excluídos, os fora do período e os de outro condomínio, e sem dado
    pessoal;
  - validação, escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\GatehouseTrafficTest`** (MariaDB, banco `*_test`): unidade pelo cadastro, acessos e visitas previstas,
  as linhas por tipo, as faixas, os totais, as visitas previstas, nada na auditoria, sem dado pessoal e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Movimento da portaria"; quando ganhar, vem o artigo da
  Central de Ajuda.
