<!-- markdownlint-disable MD024 -->
# 0262 — Cobertura das alíquotas de retenção do condomínio

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - cadastro de impostos (AP-164), só como cadastro reusado;
  - 0258 (cobertura das tarifas, mesmo desenho de origem própria ou padrão).

## Contexto

O cadastro de impostos (AP-164) guarda as alíquotas de ISS, IRRF, INSS e PIS/COFINS/CSLL para conferir as notas de
serviço: as do condomínio e o padrão da Connect, sem condomínio.

Antes de lançar uma nota, ninguém confere:

- se cada imposto tem alíquota ativa para o condomínio;
- de onde ela vem (do condomínio ou o padrão) e se o condomínio substitui o padrão;
- se a alíquota cobre serviços e materiais;
- se há alíquotas diferentes para o mesmo imposto, o que pede atenção na conferência.

Busquei `erp_taxes` em `source/`, no README das ADRs e nos handoffs 02xx. Só o cadastro usa a tabela, e nenhum relatório
confere a cobertura.

## Decisão

Rota nova: `GET /api/v1/reports/tax-coverage?condominium_id=`. O service é `TaxCoverage`, em `Source\Services\Erp`.

### Reuso

- **Impostos e aplicação:** os do cadastro (`RegistryService::definition('taxes')`).
- **Regra das listas do cadastro:** o condomínio vê as próprias e as padrão, só as ativas e sem excluídas.
- **Origem por imposto:** as próprias do condomínio, quando há; senão, as padrão.

### Linhas

A regra pura `summarize()` monta uma linha por imposto, sempre todos, com:

- `own`, `default` e `inactive_own`: alíquotas ativas próprias, ativas padrão e inativas próprias;
- `source` e `rates`: a origem usada (`condominium`, `default` ou null) e quantas alíquotas ela tem;
- `min_rate` e `max_rate`: a menor e a maior alíquota da origem;
- `covers_services` e `covers_materials`: alguma alíquota da origem se aplica a serviços ou a materiais ("todos" vale
  para os dois);
- `overrides_default`: há alíquota própria e há padrão do mesmo imposto;
- `missing`: nenhuma alíquota ativa, nem própria nem padrão.

`totals` soma `own`, `default`, `inactive_own` e `rates` das linhas. Fora das linhas, `missing_kinds` lista os impostos em
falta.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.settings.view`, as mesmas do cadastro de impostos. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** os impostos, as contagens, as alíquotas e as marcas de conferência. Não saem nome de alíquota ou
  observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\TaxCoverageTest`:**
  - a regra pura: a própria substitui a padrão; a menor e a maior alíquota; "todos" valendo para serviços e materiais;
  - a inativa que não cobre, os impostos em falta e o total igual à soma das linhas;
  - a consulta sem outro condomínio, sem excluídas e sem nome nem observação;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\TaxCoverageTest`** (MariaDB, banco `*_test`):
  - alíquotas gravadas pelo cadastro e as padrão;
  - a substituição do padrão, a inativa e os impostos em falta;
  - somente leitura e sem nome nem observação; o escopo.
- **Sem tela nova neste patch.** A tela de impostos pode ganhar o quadro da cobertura; quando ganhar, vem o artigo da
  Central de Ajuda.
