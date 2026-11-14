<!-- markdownlint-disable MD024 -->
# 0258 — Cobertura das tarifas de consumo do condomínio

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - 0045 (leituras e consumo, faixas progressivas);
  - cadastros de relógios (AP-041) e tarifas (AP-043), só como cadastros reusados.

## Contexto

O cadastro de tarifas (AP-043) guarda as faixas progressivas por tipo e vigência, do condomínio ou o padrão da Connect.
As leituras (`MeterReadings`) usam, em cada leitura, as faixas vigentes.

Antes de lançar as leituras do mês, ninguém confere:

- se cada tipo de relógio do condomínio tem tarifa vigente;
- de onde a tarifa vem (do condomínio ou o padrão) e desde quando vale;
- se a última faixa não tem limite; se tem, o consumo acima dela fica sem preço;
- se há buraco entre as faixas;
- se já há reajuste cadastrado para depois da data.

Busquei `erp_tariffs` em `source/`, no README das ADRs e nos handoffs 02xx. Só usam a tabela o cadastro e as leituras.
A conferência das leituras (`meter-reading-review`) olha o consumo, não a tarifa.

## Decisão

Rota nova: `GET /api/v1/reports/tariff-coverage?condominium_id=&on=`. O padrão de `on` é hoje. O service é
`TariffCoverage`, em `Source\Services\Erp`.

### Reuso

- **Tipos:** os do cadastro (`RegistryService::definition('tariffs')`).
- **Tarifa vigente:** a mesma regra das leituras (`MeterReadings::bands`). Vale a tarifa do condomínio com a vigência mais
  recente até a data; sem ela, a padrão. Só as ativas e sem excluídas.
- **Relógios:** os ativos do condomínio, como na leitura.

### Linhas

A regra pura `summarize()` monta uma linha por tipo, sempre todos, com:

- `meters`: relógios ativos do tipo;
- `source`, `valid_from` e `bands`: a origem da tarifa vigente em `on` (`condominium`, `default` ou null), desde quando
  vale e quantas faixas tem;
- `starts_at_zero`: a primeira faixa começa em 0;
- `open_ended`: alguma faixa sem limite;
- `gaps`: buracos entre faixas seguidas, quando a seguinte começa depois do fim da anterior;
- `next_valid_from`: a próxima vigência já cadastrada da mesma origem;
- `missing`: há relógio do tipo e nenhuma tarifa vigente.

`totals` soma `meters` e `bands` das linhas. Fora das linhas, `missing_kinds` lista os tipos em falta.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.tariffs.view` e `erp.meters.view`. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida (condomínio ou data), 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só os tipos, as contagens, as datas e as marcas de conferência. Não saem número do relógio,
  unidade, nome da faixa, preço ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\TariffCoverageTest`:**
  - a regra pura: a do condomínio substitui a padrão; vale a vigência mais recente até a data;
  - as faixas fora de ordem, o início em zero, o sem limite e o buraco de 20 a 25;
  - a próxima vigência da mesma origem (ou da padrão, quando não há nenhuma vigente);
  - o tipo em falta e o total igual à soma das linhas;
  - a consulta sem relógio inativo ou excluído, sem tarifa inativa ou de outro condomínio e sem dado do relógio nem da
    faixa; a data padrão;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\TariffCoverageTest`** (MariaDB, banco `*_test`):
  - relógios e tarifas gravados pelo cadastro, com a padrão substituída e o reajuste futuro;
  - o gás sem tarifa;
  - somente leitura e sem dado do relógio; o escopo.
- **Sem tela nova neste patch.** A tela de tarifas pode ganhar o quadro da cobertura; quando ganhar, vem o artigo da
  Central de Ajuda.
