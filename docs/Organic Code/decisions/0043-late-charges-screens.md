# 0043 — Telas de encargos: regra de cobrança, IPCA, sugestão e quebra de acordo

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0040 trouxe o cálculo de multa, juros e correção pelo IPCA, a regra por condomínio e a quebra
explícita de acordo, com as APIs `/api/v1/billing-rules`, `/api/v1/price-indexes`,
`/api/v1/agreements/suggestion` e `/api/v1/agreements/{id}/breach`. As telas ainda não usavam nada
disso: AP-165 mostrava a demonstração e o "Fazer acordo" pedia os encargos à mão (ADR 0041).

## Decisão

- **AP-165 Índices e regra de cobrança** (`pages/finance/billing-rules.php`, `Panel::billingData`):
  - a regra que vale para o condomínio da barra superior, com a origem (própria, padrão da Connect ou
    padrão do sistema); "Todos" edita o padrão da Connect;
  - multa e juros em %, convertidos para pontos-base no navegador; correção IPCA ou nenhuma; critérios
    de quebra (dias de atraso e parcelas em aberto);
  - IPCA mensal: lançar um mês ou importar arquivo `AAAA-MM;variação` (lido no navegador, sem rede);
    lista dos meses e acumulado de 12 meses;
  - ler exige `erp.billing_rules.view`; gravar, `erp.billing_rules.manage` (campos desabilitados e
    sem botões para quem só vê). O padrão da Connect e os índices exigem acesso a todos os
    condomínios, conferido pela API (403 com mensagem).
- **AP-033 Inadimplência**: coluna "Atualizado" com o total corrigido e os encargos; aviso quando
  falta mês do IPCA, com link para AP-165.
- **Fazer acordo**: ao abrir e ao marcar contas, busca a sugestão e preenche os encargos com o
  detalhamento (correção, multa, juros e meses sem índice). Se a pessoa alterar o valor, ele é
  mantido e o aviso diz isso.
- **Quebra de acordo**: o ERP só indica (selo e motivos); "Marcar como quebrado" aparece para quem
  gerencia quando `breach.due` é verdadeiro, e pede motivo (5 a 255 caracteres). Status "Quebrado"
  na lista e no filtro.

## Consequências e lacunas

- Testado sem MariaDB: render-smoke com dados simulados e teste unitário de contrato; chamadas da API
  conferidas com mocks no navegador. Falta teste em banco real.
- A sugestão é uma chamada por mudança de seleção (com espera de 250 ms).
- Salvar a regra pede confirmação, porque vale para os encargos calculados dali em diante.
