<!-- markdownlint-disable MD024 -->
# 0224 — Quadro de funcionários e folha por função e turno

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0223 (reajuste salarial em lote);
  - 0222 (mesmo cuidado com os totais e as linhas especiais).

## Contexto

O cadastro de funcionários próprios (AP-009) guarda função, turno, admissão, desligamento e salário, e o reajuste em
lote (ADR 0223) muda os salários. Nada resume o quadro para a prestação de contas e a assembleia:

- quantos porteiros há por turno;
- quanto custa a folha;
- quem entrou e quem saiu no período.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/employee-headcount?condominium_id=&from=&to=`. O service é
`EmployeeHeadcount`.

### Regra pura `summarize()`

O resumo é feito por função e turno, com os rótulos do próprio cadastro. Sem turno vira "Sem turno"; sem função, "Sem
função".

| Campo | O que conta |
|---|---|
| `active` | ativo no fim do período: cadastro ativo, admitido até `to` e sem desligamento até `to` |
| `with_salary`, `salary_total_cents` | os ativos com salário |
| `salary_avg_cents` | total ÷ ativos com salário, arredondado ao centavo (`null` se nenhum tem salário) |
| `hires` | admissões dentro do período, em qualquer situação do cadastro |
| `terminations` | desligamentos dentro do período, em qualquer situação do cadastro |

- **Totais do topo:** são a soma das linhas, com as mesmas regras, inclusive "Sem turno" e "Sem função". A média do
  topo é o total ÷ ativos com salário.
- **Período:** padrão do primeiro dia do mês de `to` até `to` (hoje); até 731 dias.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access` e `erp.employees.view`, a mesma de ver o cadastro, que já mostra o salário; sem uma
  delas, 403 (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados:** saem só os grupos e as somas. Não saem nome, CPF, telefone, e-mail ou id de funcionário.
- **Limite:** até 5000 funcionários cadastrados.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\EmployeeHeadcountTest`:**
  - a regra pura: ativos, admitido no período, desligado no período, desligado antes, admitido depois, cadastro inativo,
    "Sem turno", "Sem função" e lista vazia;
  - os totais iguais à soma das linhas;
  - a montagem no SQLite, sem apagado nem outro condomínio e sem dado pessoal;
  - o período padrão;
  - validação e escopo (404);
  - contrato;
  - rota.
- **`Integration\EmployeeHeadcountTest`** (MariaDB, banco `*_test`):
  - funcionários criados pelo formulário;
  - linhas e totais de outubro, com a média arredondada;
  - setembro, antes da admissão e do desligamento;
  - nenhum nome ou CPF no JSON;
  - nenhuma linha nova na auditoria;
  - escopo.
- **Sem tela nova neste patch.** A lista de funcionários pode ganhar o resumo "Quadro e folha"; quando ganhar, vem o
  artigo da Central de Ajuda.
