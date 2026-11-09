# 0083 — Dados dos gráficos (AP-102 a AP-109): séries mensais, só consulta

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

O catálogo tem os gráficos gerais (AP-102), de contas (AP-103), de consumo da unidade e geral
(AP-104/105), financeiro (AP-106), de inadimplência (AP-107), financeiro por conta (AP-108) e de áreas
comuns (AP-109). Os relatórios dos ADR 0081 e 0082 já calculam esses números em tabela. A coordenação
pediu os gráficos sobre essas consultas, só consulta, com o mesmo escopo e os mesmos limites.

## Decisão

- **`ChartReports`** devolve os dados, e a tela desenha. O formato é sempre
  `{labels: [AAAA-MM...], unit, series: [{key, label, data[]}]}`, um valor por mês, com zero no mês
  sem dado. A faixa é de `from` a `to` (AAAA-MM), até 24 meses, com a mesma validação do ADR 0082.
- **Gráficos:**
  - **AP-102 Gerais** (`/charts/general`): receitas e despesas por competência, o resultado e o
    vencido em aberto hoje;
  - **AP-103 Contas** (`/charts/accounts?nature=`): por conta do plano de uma natureza. As 8 maiores
    da faixa viram séries; o resto soma em "Outras";
  - **AP-104/105 Consumo** (`/charts/consumption`, `unit_id` para a unidade): consumo por tipo (água,
    gás, energia);
  - **AP-106 Financeiro** (`/charts/cash`): recebido e pago pelo mês do pagamento (caixa) e o saldo do
    mês;
  - **AP-107 Inadimplência** (`/charts/delinquency`): por competência, o cobrado das unidades, o
    vencido em aberto hoje e a taxa (vencido ÷ cobrado, em %, sem taxa no mês sem cobrança);
  - **AP-108 Financeiro por conta** (`/charts/bank-accounts`): o saldo do mês de cada conta bancária
    (entradas − saídas), com as transferências do ADR 0057;
  - **AP-109 Áreas comuns** (`/charts/common-areas`): reservas não canceladas por área e mês, com as 8
    áreas de maior uso e o resto em "Outras".
- **Escopo:** um condomínio ou todos os do usuário; fora do escopo responde 404. Cada gráfico exige a
  permissão de leitura dos dados que mostra (lançamentos, plano de contas, leituras, acordos, contas
  bancárias ou reservas).
- **Sem CSV:** os números de cada gráfico já saem em CSV pelos relatórios dos ADR 0081 e 0082.

## Consequências

- Telas (layout): os gráficos usam estas rotas. O desenho, as cores e a acessibilidade (tabela
  equivalente) ficam na frente de layout.
