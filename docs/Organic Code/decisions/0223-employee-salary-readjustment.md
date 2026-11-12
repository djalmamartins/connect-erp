<!-- markdownlint-disable MD024 -->
# 0223 — Reajuste salarial em lote dos funcionários

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0204 (reajuste de lançamentos fixos: mesmo percentual e arredondamento);
  - 0217 (renovação de contratos);
  - 0221 (mesmo padrão: ids opcionais, tudo ou nada, prévia e auditoria).

## Contexto

O cadastro de funcionários próprios (AP-009) guarda função, turno, admissão, desligamento e salário (`salary_cents`).
No dissídio da categoria (porteiros, zeladores, auxiliares de limpeza), o novo salário de cada funcionário é digitado à
mão, um por um, com a conta feita fora. O percentual é o mesmo para todos, ou para uma função.

Nada no `source/` usa `erp_employees` além do próprio cadastro.

## Decisão

Rota nova: `POST /api/v1/employees/readjust-salaries` com `{condominium_id, percent, effective_on, reason,
employee_ids?, job?, dry_run?}`. O service é `EmployeeSalaryReadjustment`. A rota fica antes do laço dos cadastros, para
não colidir com `/employees/{id}`.

### Reuso

- **`RecurringReadjustment::basisPoints` e `::readjusted`:** o mesmo percentual (de −50% a 100%, até 2 casas, diferente
  de zero) e o mesmo arredondamento ao centavo das ADR 0204 e 0217.
- **`RegistryService::save` do cadastro `employees`:** cada funcionário é regravado só com o salário trocado. Valem as
  regras (CPF, datas, unicidade) e a auditoria `update` do formulário.

### Quais funcionários entram

- **Sem `employee_ids`:** os ativos do condomínio com salário e sem desligamento até `effective_on`. Com `job`, só os
  daquela função.
- **Com `employee_ids`:** só os informados. A regra pura `problem()` recusa o funcionário:
  - não encontrado no condomínio;
  - inativo;
  - desligado até a data do reajuste;
  - sem salário cadastrado.

### Gravação

- **Tudo ou nada:** numa transação com os funcionários travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` e `_employee`, ou os erros do formulário.
- **Prévia:** `dry_run` passa pelo formulário e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `readjust` por funcionário, só quando grava. Ele guarda o
  salário antes e depois, o percentual, a data e o motivo.
- **Motivo:** obrigatório, de 5 a 255 caracteres.
- **Limite:** até 300 funcionários por vez.
- **Nada é enviado.**
- **Lançamentos fixos de salário:** não mudam aqui. O reajuste deles continua na ADR 0204.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.employees.view` e `erp.employees.manage`; sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, função, turno e o salário antes e depois. Nenhum nome, CPF, telefone ou e-mail.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\EmployeeSalaryReadjustmentTest`:**
  - a regra pura, incluindo o desligamento depois da data;
  - validação e escopo (404);
  - as recusas antes do formulário, sem transação aberta e sem gravar;
  - contrato de reuso, sem dado pessoal;
  - controller e rota.
- **`Integration\EmployeeSalaryReadjustmentTest`** (MariaDB, banco `*_test`):
  - funcionários criados pelo formulário;
  - a prévia de uma função, com o arredondamento conferido (1.999,99 × 5,5% = 2.109,99);
  - o reajuste de todos os ativos, com o desligado e o sem salário de fora;
  - a auditoria;
  - o CPF intacto;
  - nenhum nome ou CPF no JSON;
  - os ids com um desligado desfazendo o lote;
  - escopo.
- **Sem tela nova neste patch.** A lista de funcionários pode ganhar a seleção com "Reajustar salário"; quando ganhar,
  vem o artigo da Central de Ajuda.
