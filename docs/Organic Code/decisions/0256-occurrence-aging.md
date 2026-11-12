<!-- markdownlint-disable MD024 -->
# 0256 — Idade das ocorrências em aberto por prioridade

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - cadastro de ocorrências (AP-112), só como cadastro reusado;
  - 0213 (sincronização com o Help Desk);
  - 0255 (encerramento em lote das ocorrências antigas sem chamado).

## Contexto

O relatório de ocorrências (`RegistryReports::occurrences`) lista as ocorrências de um período, uma por uma. O resumo do
síndico conta as abertas.

Ninguém responde, porém, há quanto tempo as abertas esperam:

- quantas de prioridade alta estão abertas há mais de 30 ou 90 dias;
- quantas dependem de um chamado no Help Desk;
- quantas, sem chamado, já podem ser encerradas em lote (ADR 0255).

Busquei `erp_occurrences` em `source/`, no README das ADRs e nos handoffs 02xx. A lista por período, o painel e o resumo
contam por situação. Nenhum relatório conta por idade e prioridade.

## Decisão

Rota nova: `GET /api/v1/reports/occurrence-aging?condominium_id=&on=`. O padrão de `on` é hoje. O service é
`OccurrenceAging`, em `Source\Services\Erp`.

### Reuso

- **Prioridades:** as do cadastro (`RegistryService::definition('occurrences')`).
- **Regra das listas:** sem excluídas.

### Linhas

A regra pura `summarize()` monta uma linha por prioridade, sempre todas, com as ocorrências até a data `on`:

- `open` e `running`: abertas e em andamento;
- `age_0_30`, `age_31_90` e `age_over_90`: as em aberto pelos dias desde a data da ocorrência; somam `open` + `running`;
- `with_ticket`: em aberto com chamado no Help Desk;
- `stale_without_ticket`: em aberto sem chamado há mais de 90 dias, o padrão do ADR 0255;
- `done`: resolvidas.

Prioridade desconhecida conta como "Média". `totals` soma as linhas. Fora das linhas, `oldest_open_on` traz a data da
ocorrência aberta mais antiga, ou null quando não há nenhuma.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.occurrences.view`, as mesmas do cadastro. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida (condomínio ou data), 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só as prioridades, as contagens e a data mais antiga. Não saem assunto, descrição, unidade,
  documento ou chamado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\OccurrenceAgingTest`:**
  - o resumo por prioridade, com as faixas de idade (90 dias ainda é 31 a 90), a antiga com chamado que não conta como
    sem chamado e a desconhecida como Média;
  - as faixas que somam as em aberto e o total igual à soma das linhas;
  - a consulta sem outro condomínio, sem excluídas, sem as posteriores à data e sem dado da ocorrência; a data padrão;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\OccurrenceAgingTest`** (MariaDB, banco `*_test`):
  - ocorrências gravadas pelo cadastro, uma em andamento e uma resolvida;
  - os totais e a mais antiga;
  - somente leitura e sem dado da ocorrência; o escopo.
- **Sem tela nova neste patch.** A lista de ocorrências pode ganhar o quadro da idade; quando ganhar, vem o artigo da
  Central de Ajuda.
