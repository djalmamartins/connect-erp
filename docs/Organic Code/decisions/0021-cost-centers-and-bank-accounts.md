# 0021 — Centros de custo (CN-002) e contas bancárias (CN-003)

Data: 2026-11-08 · Status: aceito (ciclo autônomo da frente financeira, por delegação do Djalma; aguarda integração no container)

## Contexto

Os lançamentos (AP-020) vão referenciar a conta do plano de contas (ADR 0019), o centro de custo
e a conta bancária. A frente de layout incluiu no catálogo as páginas próprias da Connect CN-002
(`/financeiro/centros-de-custo`) e CN-003 (`/financeiro/contas-bancarias`), com specs em
`docs/specs/pages/cn-002-*.md` e `cn-003-*.md`. As regras dessas specs são INFERIDAS, e os campos
abaixo são PROPOSTOS.

## Decisão

- **Os dois cadastros usam o motor** (ADR 0014) e ficam no grupo Financeiro.
- **Centros de custo** (`cost_centers`, tabela `erp_cost_centers`, migration `20261108_0503`):
  - escopo `optional`: sem condomínio = centro padrão da Connect, e só quem acessa todos os
    condomínios o cria ou altera;
  - código (letras, números, `.`, `-`, `_`, até 20), único por plano; nome; observações.
- **Contas bancárias** (`bank_accounts`, tabela `erp_bank_accounts`, migration `0504`):
  - escopo `required`: toda conta pertence a um condomínio;
  - apelido, banco (código COMPE de 3 dígitos), agência, conta com dígito, tipo (corrente,
    poupança, aplicação), carteira de cobrança, saldo inicial e a data dele (a data é
    obrigatória quando há saldo);
  - **uma conta principal ativa por condomínio** (`single_active`);
  - a mesma conta (banco, agência e número) não se repete no condomínio;
  - **centro de custo padrão** opcional (`ref` para `cost_centers`, do padrão ou do mesmo
    condomínio). Um centro de custo usado por conta ativa não pode ser excluído (409).
  - **Sem credenciais**: tokens, certificados e chaves de API bancária ficam fora deste
    cadastro (`docs/banking/BANKING_INTEGRATIONS.md`).
- **Conta bancária não aponta para o plano de contas.** O plano (ADR 0019) só tem natureza receita
  e despesa, e a conta bancária é o lugar onde o dinheiro fica, não uma conta de resultado. Os
  lançamentos é que vão ligar conta contábil, centro de custo e conta bancária.
- Permissões `erp.cost_centers.view/manage` e `erp.bank_accounts.view/manage` (migration `0505`).
- O OpenAPI dos cadastros documenta 409 também para registros apontados por campos `ref`.

## Consequências

- O saldo inicial vale como dado de abertura. O saldo corrente vai sair dos lançamentos.
- O saldo inicial é sempre positivo ou zero (centavos sem sinal). Uma conta aberta com saldo
  negativo vai precisar de um lançamento de ajuste.
- Testes: `tests/legacy/Unit/CostCenterBankAccountDefinitionTest.php` e
  `tests/legacy/Integration/CostCenterBankAccountTest.php`.
