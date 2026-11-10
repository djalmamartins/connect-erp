# ADR 0149 — Telas dos lançamentos patrimoniais e do vínculo da aplicação

- Situação: aceita (10/11/2026)
- Contexto: serviços e API dos ADRs 0169 (`PatrimonialEntries`) e 0170 (`Investments`), sem tela

## Contexto

O ADR 0169 criou os lançamentos patrimoniais (partida dobrada entre fundos e PL, com estorno) e o ADR 0170
ligou a aplicação financeira à conta corrente, mas os dois ficaram só na API. O ADR 0170 deixou pendente o
campo de vínculo no formulário do cadastro de contas bancárias.

## Decisão

- **CN-006 Lançamentos patrimoniais** (página própria da Connect, Financeiro › Lançamentos,
  `/financeiro/lancamentos-patrimoniais`), por `PatrimonialEntryScreens` e `pages/finance/patrimonial-entries`:
  - lista do período (padrão: 1º de janeiro até hoje) pelo mesmo serviço da API, do condomínio da barra ou de
    todos do escopo (com a coluna do condomínio);
  - com o condomínio na barra e `erp.entries.manage`: formulário de lançamento (data, descrição, valor, débito,
    crédito, conta bancária e lançamento de origem opcionais) e estorno na linha, com motivo e data;
  - o seletor de contas traz só as patrimoniais analíticas ativas do padrão ou do condomínio, sem as calculadas
    pelo balanço (7.1, 7.2, 8.1 e subcontas); a API continua decidindo;
  - gravações pelo erp.js na própria API, reaproveitando o bloco de formulários das telas de acesso
    (`data-erp-api-forms` ao lado de `data-erp-access`), com CSRF no cabeçalho.
- **Vínculo da aplicação no cadastro de contas bancárias (CN-003):** campo `parent_bank_account_id`
  ("Conta corrente da aplicação") no motor de cadastros:
  - `ref` para contas bancárias com `ref_where kind = corrente`; o motor já exige registro ativo do mesmo
    condomínio, audita a mudança e impede excluir a conta corrente enquanto houver aplicação apontando;
  - nova regra do motor `only_if` no campo: só aceita valor quando outro campo tem o valor indicado (aqui,
    `kind = aplicacao`); o formulário esconde e limpa o campo nos outros casos;
  - são as mesmas regras de `Investments::link`, que segue valendo pela API.
- **Balanço (AP-081):** as contas bancárias do Disponível mostram "(aplicação)" ou "(poupança)", como o CSV.

## Consequências

- Artigos novos ou atualizados na Central de Ajuda: `financeiro/lancamentos-patrimoniais` (novo) e
  `financeiro/contas-bancarias` (aplicação). O print do CN-006 sai no Mac com `bash tools/help/prints.sh`.
- Testes: `PatrimonialEntryScreensIntegrationTest` (contas aceitas sem as calculadas, lista, só leitura sem
  condomínio; vínculo só para aplicação, só com conta corrente e só do mesmo condomínio) e o smoke do CN-006.
- Pendente: tela de aplicar e resgatar (a API do ADR 0170 existe; hoje a transferência do AP-029 faz o mesmo
  movimento) e a importação patrimonial por planilha (ADR 0171).
