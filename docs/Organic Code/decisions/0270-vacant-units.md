<!-- markdownlint-disable MD024 -->
# 0270 — Unidades vagas e o que ficou nelas

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0206 (saída de moradores da unidade e o aviso da dívida vencida);
  - 0240 (perfil de ocupação das unidades);
  - 0265, 0267 e 0269 (lotes de visitantes, veículos e animais de unidades sem morador ativo).

## Contexto

O perfil de ocupação (ADR 0240) conta as unidades pelos vínculos. Os lotes de visitantes, veículos e animais (ADR 0265,
0267 e 0269) acertam um cadastro por vez. A saída da unidade (ADR 0206) avisa da dívida de uma unidade por vez.

A administradora não vê, de uma vez:

- quantas unidades ativas estão sem morador ativo, e há quanto tempo;
- quantas ainda têm visitante liberado, veículo ou animal ativo;
- quantas carregam conta vencida em aberto (a dívida é da unidade e continua).

Ela usa isso antes de rodar os lotes e para a cobrança das unidades vazias.

Busquei "sem morador" e as tabelas dos cadastros em `source/`, no README das ADRs e nos handoffs 02xx. O perfil de
ocupação conta `no_links` (unidades sem vínculo), mas não cruza com os outros cadastros nem com a dívida.

## Decisão

Rota nova: `GET /api/v1/reports/vacant-units?condominium_id=`, logo depois de `POST /pets/deactivate-unattached` e antes do
laço dos cadastros. O service é `VacantUnits`, em `Source\Services\Erp`.

### Reuso

- **Morador ativo:** a regra dos lotes e das listas do cadastro (vínculo ativo, sem excluído e sem fim de vínculo
  passado).
- **Registros que ficaram:** as situações dos cadastros (só os ativos, sem os excluídos, vinculados à unidade).
- **Dívida:** a regra da saída da unidade (0206): receita ativa, sem pagamento e com vencimento antes de hoje.

### Regra pura `ageBucket()` e `summarize()`

Vaga é a unidade ativa sem morador ativo.

- **`units`:** `active`, `occupied` e `vacant` (que somam `active`), `with_leftovers` (vagas com algum registro, sem contar
  duas vezes) e `clean`.
- **`vacancy`:** as vagas pelo fim de vínculo mais recente informado: `never_linked` (nenhum vínculo no cadastro),
  `unknown_date` (vínculos sem fim informado), `ate_30`, `de_31_a_180` e `mais_de_180` dias. Soma `units.vacant`.
- **`rows`:** uma linha por cadastro (visitantes liberados, veículos e animais ativos), sempre as três, com as vagas que
  têm registro e os registros. `totals.records` é a soma das linhas.
- **`debt`:** as vagas com conta vencida em aberto, as contas e o valor em centavos.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.units.view`, `erp.residents.view`, `erp.visitors.view`, `erp.vehicles.view`,
  `erp.pets.view` e `erp.entries.view`, as dos cadastros e dos lançamentos que o relatório conta. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só faixas, cadastros e contagens. Não saem nome, documento, placa, rótulo ou id de unidade, ou
  observação.
- **Não toca** o portal do morador, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VacantUnitsTest`:**
  - as faixas da vaga com as bordas de 30 e 180 dias;
  - as três linhas, as unidades sem contar duas vezes e a dívida só das vagas;
  - totais iguais à soma das linhas;
  - morador ativo, com fim passado, inativo e excluído;
  - registros inativos, excluídos, sem unidade e de unidade inativa de fora;
  - conta paga, a vencer, inativa e de despesa de fora;
  - nenhum dado pessoal;
  - a validação, o escopo, o contrato, o controller e a rota.
- **`Integration\VacantUnitsTest`** (MariaDB, banco `*_test`):
  - as vagas, as faixas e os registros com os cadastros;
  - somente leitura e sem dado pessoal;
  - os veículos das vagas iguais à prévia da inativação em lote (0267);
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. O painel de unidades pode ganhar o quadro das vagas
  com os botões dos lotes; quando ganhar, vem o artigo.
