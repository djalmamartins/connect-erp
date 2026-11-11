<!-- markdownlint-disable MD024 -->
# 0240 — Perfil de ocupação das unidades

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-005 (proprietários e inquilinos) e AP-006 (unidades), só como fonte dos dados;
  - 0185 (qualidade do cadastro: aponta o que falta, unidade por unidade);
  - 0236 (mesmo padrão de relatório de cadastro: regra pura, totais iguais à soma das linhas).

## Contexto

O relatório das unidades (`RegistryReports::units`) lista unidade por unidade, a qualidade do cadastro (0185) aponta o
que falta (unidade sem proprietário, documento inválido) e o painel da unidade mostra uma de cada vez. Ninguém vê o
perfil do condomínio: quantas unidades estão alugadas, quantas têm morador sem inquilino, quantas só têm o proprietário
cadastrado e quantas estão sem nenhum vínculo, por tipo de unidade. A administradora usa isso para a assembleia (quem
vota e quem só mora), para a portaria e para conferir o cadastro depois de mudanças. Busquei ocupação, inquilino e
`erp_residents` em `source/`, no README das ADRs e nos handoffs 02xx: nenhum relatório resume a ocupação.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/unit-occupancy?condominium_id=&on=`. O service é `UnitOccupancy`, em
`Source\Services\Erp`.

### Reuso

- **Tipos de unidade e de vínculo:** os do cadastro (`RegistryService::definition`: `units` e `residents`), com os mesmos
  rótulos.
- **Regra das listas:** só ativos, sem excluídos.
- **Vínculo valendo na data `on`** (padrão hoje): ativo, com o início (se houver) até `on` e o fim (se houver) a partir de
  `on`. Assim um inquilino com o fim do vínculo já passado não conta, mesmo que o cadastro ainda o marque ativo.

### Regra pura `classify()` e `summarize()`

Cada unidade ativa cai em uma situação, pela ordem:

| Situação | Quando |
|---|---|
| `rented` | tem inquilino |
| `occupied` | sem inquilino e com morador |
| `owner_only` | sem inquilino nem morador e com proprietário |
| `other_links` | só responsável financeiro |
| `no_links` | nenhum vínculo valendo |

- Uma linha por situação, sempre as cinco, com `units` e `by_type` (os tipos do cadastro) somando `units`; `totals` soma
  as linhas e é o número de unidades ativas.
- `links` conta os vínculos valendo por tipo (somando `linked`); `unlinked` conta os que não apontam para uma unidade
  ativa (só o texto da unidade, cadastro antigo).

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.units.view` e `erp.residents.view`, as mesmas dos cadastros; sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** só as situações, os tipos e as contagens. A consulta nem lê nome, documento, contato, rótulo de
  unidade ou observação.
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\UnitOccupancyTest`:**
  - a regra pura: as cinco situações, o vínculo valendo na data (inativo, futuro, encerrado, fim no próprio dia), os
    vínculos sem unidade ativa, os tipos e os totais;
  - a montagem no SQLite: sem as unidades inativas, excluídas e de outro condomínio, sem os vínculos excluídos, e sem dado
    pessoal;
  - validação, escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\UnitOccupancyTest`** (MariaDB, banco `*_test`): unidades e vínculos gravados pelos cadastros, as
  situações, os tipos, os vínculos, nada na auditoria, sem dado pessoal e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Ocupação das unidades"; quando ganhar, vem o artigo da
  Central de Ajuda.
