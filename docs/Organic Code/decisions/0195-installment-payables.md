# 0195 — Despesa parcelada

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0022 e 0024 (lançamentos e contas a pagar), 0031 e 0100 (competência fechada), 0191 (baixa em
  lote)

## Contexto

O condomínio compra ou contrata algo parcelado o tempo todo: a obra do hall em 6 vezes, o portão em 10 vezes, a
manutenção anual em 12 boletos. Hoje a administradora digita cada parcela como uma conta a pagar: descrição, valor,
vencimento e competência, uma a uma. É lento e propenso a erro: a soma não bate com o total, um vencimento cai num dia
que o mês não tem ou uma parcela fica esquecida.

## Decisão

Rota nova: `POST /api/v1/entries/installments` `{condominium_id, chart_account_id, description, total, installments,
first_due_on, competence?, cost_center_id?, supplier_id?, document_number?, bank_account_id?, notes?, dry_run?}`. O
service é `InstallmentPayables`.

### Regras

- **Valor:** o total, em 2 a 60 parcelas iguais. Os centavos que sobram vão para a primeira (regra pura `split`).
- **Vencimentos:** a primeira data e, depois, mês a mês no mesmo dia. Quando o mês não tem esse dia, vale o último dia
  do mês: 31/01, 28/02, 31/03; em ano bissexto, 29/02 (regra pura `schedule`).
- **Competência:**
  - informada: vale para todas (a despesa é do mês da compra);
  - vazia: cada parcela fica na competência do próprio vencimento.
- **Descrição:** `<descrição> (i/N)`, com até 150 caracteres antes do sufixo.
- **Campos comuns:** conta, centro de custo, fornecedor, documento, conta bancária sugerida e observações valem para
  todas as parcelas.
- **Nascem em aberto:** a baixa continua pela baixa em lote (ADR 0191) ou pelo formulário.

### As regras do formulário valem para cada parcela

Cada parcela é gravada pelo `RegistryService::save` do cadastro `payables` (contas a pagar). Assim valem as mesmas
regras da tela:

- escopo do condomínio;
- conta analítica de despesa ativa;
- centro de custo e fornecedor válidos;
- conta bancária do condomínio;
- competência fechada (ADR 0031, com a leitura travada do ADR 0100);
- auditoria `create` de cada lançamento.

### Tudo ou nada

- **Transação:** tudo roda numa só (o `save` não abre outra quando já há uma aberta).
- **Parcela recusada:** dá 422, com os erros por campo e `_installment` ("Parcela 2 de 3."), e nenhuma parcela fica.
- **`dry_run`:** monta o plano (descrição, valor, vencimento e competência de cada parcela) e confere a entrada, sem
  gravar. É para a tela mostrar antes de confirmar.

### Permissões e escopo

- **Permissão:** exige `erp.access`, `erp.entries.view` e `erp.entries.manage`, as mesmas de lançar conta a pagar.
- **CSRF:** pelo `ApiAuthenticated`.
- **Escopo:** fora do escopo dá 404 antes de gravar.

## Consequências

- **Testes:**
  - `Unit\InstallmentPayablesTest` (SQLite) confere:
    - as regras puras: sobra dos centavos, último dia do mês, virada do ano, ano bissexto;
    - o plano do `dry_run`, com e sem competência;
    - as validações, o escopo, o controller e a rota.
  - `Integration\InstallmentPayablesTest` (MariaDB) confere:
    - as 3 contas a pagar gravadas pelo formulário, em aberto, auditadas;
    - a competência fechada na segunda parcela e a conta de receita, que desfazem tudo.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.** As parcelas se reconhecem pelo sufixo `(i/N)` e pelo documento.
- **Sem tela nova neste patch.** O botão "Parcelar" em contas a pagar fica para a frente de layout; quando a tela
  existir, ela ganha o artigo da Central de Ajuda.
- **Fora de escopo:**
  - parcelar receita (para a unidade isso é acordo, ADR 0039);
  - parcelas com valores diferentes;
  - um vínculo explícito entre as parcelas (coluna de grupo). Esse vínculo fica para um ciclo com migration, se o
    Djalma quiser cancelar "as parcelas restantes" de uma vez.
