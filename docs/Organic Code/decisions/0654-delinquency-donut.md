# 0654 — Rosca de inadimplência fina, inteira e com destaque no centro

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 16:40, com print do card "Inadimplência por atraso" do painel do ERP:
  "o circulo não esta legal, pode corrigir".

## Contexto

O componente `components/charts/donut.php` desenhava o anel com traço 5 num raio de 15,9 dentro de um
`viewBox` 36×36. A borda externa do anel (15,9 + 2,5 = 18,4) passava do desenho (18), então o círculo
saía cortado nos quatro lados, grosso e com cara de achatado. O SVG também podia encolher no flex e o
giro de −90° era feito por CSS no SVG inteiro.

## Decisão

- `viewBox` 42×42 e traço 3,6: o anel cabe inteiro, fica mais fino e proporcional (132 px).
- Respiro de 0,8 ponto entre as fatias e trilho cinza-claro (`--erp-neutral-soft`) por baixo.
- Giro feito dentro do SVG (`<g transform="rotate(-90 21 21)">`), para o texto do centro não girar.
- Destaque opcional no centro (`$donutCenter`, `$donutCenterCaption`). No painel, mostra a faixa com
  mais dinheiro em aberto (ex.: "93% · até 30 dias").
- Legenda em grade (cor, faixa, percentual alinhado à direita) e centralizada com a rosca; quando o
  card é estreito, a legenda desce para baixo da rosca.
- Sem mudança de dados, cálculo, API ou permissões.

## Consequências

Qualquer tela que use a rosca ganha o novo desenho. Os percentuais e os valores por faixa continuam os
mesmos. Depois de mexer no `erp.css`, o `erp.min.css` foi gerado de novo (ErpBundleFreshnessTest).
