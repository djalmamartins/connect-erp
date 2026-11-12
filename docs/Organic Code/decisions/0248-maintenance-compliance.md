<!-- markdownlint-disable MD024 -->
# 0248 — Cumprimento do plano de manutenções num período

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - ADR 0051 (cadastros operacionais: AP-110 manutenções), só como cadastro reusado;
  - resumo operacional (OperationsSummary) e painel operacional (contam as do mês, as atrasadas e as da semana);
  - conclusão de manutenções em lote (MaintenanceCompletion).

## Contexto

O cadastro de manutenções (AP-110) guarda o serviço, a data prevista, a periodicidade, a conclusão e o laudo. Hoje:

- o resumo operacional conta as previstas e as concluídas do mês;
- o painel operacional conta as atrasadas e as da semana;
- a conclusão em lote dá baixa.

Ninguém mede, porém, o cumprimento do plano no ano:

- quantas manutenções previstas foram feitas no prazo;
- quantas com atraso, e com quantos dias em média;
- quantas seguem atrasadas sem baixa, por periodicidade;
- quantas concluídas estão sem o laudo.

A administradora precisa disso na prestação de contas e com o conselho (manutenção preventiva de elevador, bombas,
extintores e para-raios).

Busquei `erp_maintenances` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro, o resumo e o painel
operacional, o painel financeiro e a conclusão em lote usam a tabela; nenhum relatório mede o cumprimento.

## Decisão

Rota nova: `GET /api/v1/reports/maintenance-compliance?condominium_id=&from=&to=`. O service é `MaintenanceCompliance`,
em `Source\Services\Erp`.

### Reuso

- **Periodicidades:** as do cadastro (`RegistryService::definition('maintenances')`).
- **Regra das listas:** sem excluídas.
- **Regra de anexo do cadastro:** o laudo vale quando existe no Disco Virtual do mesmo condomínio e não foi removido.

### Período e situação

- **Período:** as manutenções com a data prevista de `from` a `to` (padrão os últimos 12 meses até hoje; até 3 anos).
- **Data de referência (`reference_on`):** o fim do período ou hoje, o que vier antes.

A regra pura `classify()` dá a situação de cada manutenção:

- `cancelled`: cancelada;
- `done_on_time`: concluída até a data prevista (ou sem data de conclusão);
- `done_late`: concluída depois da data prevista, com os dias de atraso;
- `overdue`: agendada ou em execução com a data prevista antes da data de referência;
- `upcoming`: agendada ou em execução com a data prevista a partir da data de referência.

A regra pura `summarize()` monta uma linha por periodicidade, sempre as do cadastro, com:

- `planned` (todas menos as canceladas) e as cinco contagens;
- `avg_delay_days`: a média dos dias de atraso das concluídas com atraso (`null` sem nenhuma);
- `on_time_rate`: as concluídas no prazo sobre as que venceram até a data de referência, em % (`null` sem nenhuma);
- `done_without_document`: as concluídas sem o laudo.

`totals` soma as linhas e recalcula as médias.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.maintenances.view`, as mesmas do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só as periodicidades, as contagens e as médias. Não saem serviço, fornecedor, descrição ou
  documento.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\MaintenanceComplianceTest`:**
  - a regra pura da situação: no prazo, sem data de conclusão, com atraso, atrasada, no próprio dia, em execução e
    cancelada;
  - o resumo: as periodicidades do cadastro, a periodicidade desconhecida como "Única", as médias, a taxa e o total igual
    à soma das linhas;
  - a consulta no período, sem as de fora do período, excluídas ou de outro condomínio, com o laudo removido contado como
    sem laudo, e sem dado da manutenção; o período padrão;
  - validação (datas, ordem, até 3 anos), escopo (404) e contrato; controller e rota.
- **`Integration\MaintenanceComplianceTest`** (MariaDB, banco `*_test`):
  - manutenções gravadas pelo cadastro: no prazo, com atraso, atrasada, de hoje, cancelada e uma fora dos 12 meses;
  - as linhas, a média de atraso, a taxa e as concluídas sem laudo;
  - somente leitura e sem dado da manutenção; o escopo.
- **Sem tela nova neste patch.** A tela de manutenções pode ganhar o quadro do cumprimento; quando ganhar, vem o artigo da
  Central de Ajuda.
