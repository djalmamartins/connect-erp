<!-- markdownlint-disable MD024 -->
# 0225 — Desligamento em lote de funcionários

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0223 (reajuste salarial em lote);
  - 0224 (quadro e folha, que já lê o desligamento).

## Contexto

Na troca da equipe própria por uma terceirizada (portaria, limpeza), vários funcionários saem no mesmo dia. Hoje cada
desligamento é digitado no cadastro (AP-009), um por um. O quadro e a folha (ADR 0224) e o reajuste (ADR 0223) já leem a
data de desligamento.

## Decisão

Rota nova: `POST /api/v1/employees/terminate` com `{condominium_id, employee_ids, terminated_on, reason, dry_run?}`. O
service é `EmployeeTermination`. A rota fica antes do laço dos cadastros, para não colidir com `/employees/{id}`.

### Reuso

- **`RegistryService::save` do cadastro `employees`:** cada funcionário é regravado só com a data de desligamento.
  Valem as regras do formulário (desligamento depois da admissão) e a auditoria `update`.
- **Situação do cadastro:** não muda. O desligamento é a data, como no formulário.
- **Demais campos:** salário e datas são repassados no formato do formulário, para não mudar nada além da data.

### Regra pura `problem()`

Recusa o funcionário:

- não encontrado no condomínio;
- inativo;
- já desligado (a data não é trocada aqui);
- admitido depois da data do desligamento.

### Gravação

- **Ids obrigatórios:** desligar é sempre escolha.
- **Data:** até 30 dias à frente (aviso prévio já combinado).
- **Tudo ou nada:** numa transação com os funcionários travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` e `_employee`, ou os erros do formulário.
- **Prévia:** `dry_run` passa pelo formulário e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `terminate` por funcionário (data e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres.
- **Limite:** até 300 funcionários por vez.
- **Nada é enviado.** Rescisão, eSocial e lançamentos ficam fora daqui.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.employees.view` e `erp.employees.manage`; sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, função, turno, admissão e desligamento. Nenhum nome, CPF, telefone, e-mail ou salário.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\EmployeeTerminationTest`:**
  - a regra pura;
  - validação, incluindo a data mais de 30 dias à frente;
  - escopo (404);
  - as recusas antes do formulário, sem transação aberta e sem gravar;
  - contrato de reuso;
  - controller e rota.
- **`Integration\EmployeeTerminationTest`** (MariaDB, banco `*_test`):
  - funcionários pelo formulário;
  - a prévia sem gravar e sem dado pessoal;
  - o desligamento com salário e situação intactos;
  - auditoria `terminate`;
  - o quadro da 0224 refletindo os desligamentos;
  - já desligado e admitido depois desfazendo o lote;
  - a data antiga preservada;
  - escopo.
- **Sem tela nova neste patch.** A lista de funcionários pode ganhar a seleção com "Desligar"; quando ganhar, vem o
  artigo da Central de Ajuda.
