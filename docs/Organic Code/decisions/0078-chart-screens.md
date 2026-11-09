# 0078 — Telas dos gráficos: um eixo por painel e a tabela equivalente

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Dados: ADR 0083

## Contexto

O ADR 0083 entregou os dados dos gráficos pela API (`/api/v1/charts/...`), em séries mensais. As
páginas AP-102 a AP-109 ainda mostravam a demonstração, com o componente `charts/bars`, que só
desenha até duas séries positivas.

## Decisão

- **Uma tela para os oito gráficos** (`pages/reports/chart`), com os dados lidos no servidor pelo
  mesmo serviço da API (`ChartScreens`). O controlador entrega os painéis já medidos (onde cada barra
  começa e quanto mede, em %) e a tabela; a tela só desenha e formata. Sem biblioteca de gráficos.
- **Permissões iguais às da API:** gerais, financeiro e por conta pedem `erp.entries.view`; contas
  pede também `erp.chart.view`; por conta pede também `erp.bank_accounts.view`; consumo pede
  `erp.meter_readings.view`; inadimplência, `erp.agreements.view`; áreas comuns,
  `erp.reservations.view`. Sem permissão ou sem banco, a página segue com a demonstração.
- **Filtros (GET):**
  - `de` e `ate` em AAAA-MM. O padrão são os últimos 12 meses, e o serviço limita a faixa a 24 meses.
  - `natureza` no AP-103, com despesas como padrão.
  - `unidade` no AP-104. Sem unidade escolhida, a tela pede a unidade antes de consultar.
  - Faixa inválida mostra o erro do serviço, sem o gráfico.
- **Um eixo por painel, nunca dois:**
  - séries de unidades diferentes vão para painéis separados, como a taxa em % da inadimplência;
  - no consumo, água, gás e energia ficam um em cada painel, cada um na sua escala.
- **Forma:**
  - contas (AP-103) e áreas comuns (AP-109) somam um todo, então as barras ficam empilhadas;
  - os outros gráficos têm barras lado a lado, com a linha do zero quando há valor negativo
    (resultado, saldo do mês, saldo por conta).
- **Cores:** paleta categórica de oito cores em ordem fixa, conferida para daltonismo, e "Outras" em
  cinza neutro. Três cores ficam abaixo de 3:1 sobre o branco. Por isso a legenda é sempre visível e
  a tabela vem logo abaixo, com os mesmos números.
- **Tabela do gráfico:** uma linha por mês e uma coluna por série, com o total do período. A taxa em
  % não soma e fica sem total.
- **Valor de cada barra** no título (passar o mouse), com a série, o mês e o valor formatado.
- **Imprimir** pelo navegador. A API dos gráficos não exporta CSV, e por isso a tela também não.

## Fora deste ADR

- Exportar os gráficos em CSV ou imagem.
- Gráficos do painel inicial (AP-001), que seguem com a demonstração.

## Consequências

- O smoke cobre os oito gráficos, a linha do zero, o painel da taxa, o consumo por tipo, a unidade
  pendente, o vazio e o erro de período.
- Nada foi testado contra MariaDB real nesta rodada.
