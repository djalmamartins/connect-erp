<!-- markdownlint-disable MD024 -->
# 0242 — Vínculos perto do fim ou vencidos, por faixa de prazo

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0241 (encerramento em lote dos vínculos com o fim já passado): este relatório antecipa o que ele vai pegar;
  - 0240 (perfil de ocupação: mesmo desenho de relatório, com os vínculos do cadastro);
  - 0206 (saída de moradores da unidade).

## Contexto

O cadastro de proprietários e inquilinos guarda o fim do vínculo (`ends_on`). O perfil de ocupação (0240) desconta os
vencidos e o encerramento em lote (0241) os acerta. Ninguém vê, porém, quantos contratos de locação terminam nos
próximos meses nem quantos já venceram sem baixa.

Busquei `ends_on` em `source/`, no README das ADRs e nos handoffs 02xx:

- o painel operacional e o calendário de obrigações cobrem seguros, contratos de fornecedor e mandatos;
- a renovação de contratos trata dos contratos de fornecedor;
- nenhum relatório agrupa os vínculos das unidades pelo fim.

## Decisão

Rota nova: `GET /api/v1/reports/resident-link-endings?condominium_id=&on=`. O service é `ResidentLinkEndings`, em
`Source\Services\Erp`.

### Reuso

- **Tipos de vínculo:** os do cadastro (`RegistryService::definition('residents')`).
- **Regra das listas:** só vínculos ativos e sem excluídos.
- **Regra de vencido do 0241:** fim anterior à data `on` (padrão hoje); o próprio dia ainda vale.

### Faixas

A regra pura `bucket()` põe cada vínculo ativo com fim numa faixa:

- `expired_over_90`, `expired_31_90`, `expired_1_30`: o fim passou há tantos dias;
- `ending_0_30`, `ending_31_60`, `ending_61_90`: o fim chega em tantos dias (0 = termina na data `on`).

A regra pura `summarize()` monta uma linha por faixa, sempre as seis, com:

- `links`;
- `by_relationship`, com os tipos do cadastro, somando `links`;
- `inactive_unit`, os que apontam para unidade inativa ou excluída.

`totals` é a soma das linhas. Fora das linhas ficam:

- `open_ended`: os vínculos sem fim;
- `later`: os com fim depois de 90 dias;
- `closable`: os vencidos com unidade ativa ou sem unidade, ou seja, o que o 0241 encerraria com `before = on`.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.residents.view`, as mesmas do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só as faixas, os tipos e as contagens. Não saem nome, documento, contato, unidade, data individual
  ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ResidentLinkEndingsTest`:**
  - a regra pura das faixas, com os limites de 30, 31, 90 e 91 dias, o próprio dia e a data com hora;
  - o resumo: as seis linhas, a soma por tipo, o total igual à soma das linhas, `closable`, `open_ended` e `later`;
  - a consulta, sem encerrados, excluídos ou outro condomínio, com a unidade excluída contada como inativa e sem dado
    pessoal nem data individual;
  - validação, escopo (404) e contrato de reuso; controller e rota.
- **`Integration\ResidentLinkEndingsTest`** (MariaDB, banco `*_test`):
  - vínculos gravados pelo cadastro, um por faixa, um sem fim, um depois de 90 dias, um encerrado e um de unidade depois
    inativada;
  - somente leitura e sem dado pessoal;
  - `closable` e `inactive_unit` iguais à prévia do encerramento em lote (0241); o escopo.
- **Sem tela nova neste patch.** A lista de proprietários e inquilinos pode ganhar o resumo por faixa ao lado de
  "Encerrar os vencidos"; quando ganhar, vem o artigo da Central de Ajuda.
