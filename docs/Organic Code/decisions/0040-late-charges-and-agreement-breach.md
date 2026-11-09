# 0040 — Multa, juros, correção pelo IPCA e quebra de acordo

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma; padrões ajustáveis na issue #46)

## Contexto

O ADR 0039 deixou multa, juros e desconto como valores digitados, porque a regra não estava aprovada.
O Djalma pediu que a equipe decidisse sem consultá-lo, e a coordenação fixou os padrões abaixo. Ele
pode mudá-los a qualquer momento (issue #46), por condomínio ou no padrão da Connect.

## Decisão

- **Padrões da Connect** (`erp_billing_rules` com `condominium_id` NULL, migration 0522):
  - multa de 2% (Código Civil, art. 1.336, § 1º);
  - juros de 1% ao mês, pro rata die (dias de atraso / 30);
  - correção pelo IPCA;
  - quebra de acordo com parcela mais de 30 dias atrasada ou com 2 parcelas vencidas em aberto.
- **Configuráveis por condomínio:** cada condomínio pode ter a própria regra. Sem regra própria vale o
  padrão; sem linha nenhuma, o padrão embutido no código (`LateCharges::DEFAULT_RULE`). Alterar o
  padrão exige acesso a todos os condomínios. Valores em pontos-base (200 = 2%), validados.
- **Índice sem rede:** a variação mensal do IPCA (`erp_price_indexes`) é lançada à mão ou importada de
  texto `AAAA-MM;variação`, validado inteiro antes de gravar. Lançar um mês de novo substitui o valor.
  O índice vale para todos os condomínios, e só quem acessa todos pode gravá-lo.
- **Cálculo** (`LateCharges::compute`, regra pura, em centavos):
  - correção: variação acumulada dos meses do vencimento até o mês anterior à data de referência (o
    índice do mês corrente ainda não saiu). **Mês sem índice entra como zero e aparece em
    `missing_index`**;
  - multa e juros sobre o valor corrigido.
  - Sem atraso (vence hoje ou depois), tudo zero.
- **Onde aparece:**
  - na inadimplência (`charges` por conta, mais totais por unidade e geral, com os meses sem índice);
  - na sugestão para o acordo (`GET /api/v1/agreements/suggestion`). O valor vai como `fees_cents` no
    `POST /api/v1/agreements`, e quem faz o acordo pode mudar.
- **Quebra de acordo:**
  - calculada pela regra do condomínio (`breach` no GET do acordo: se atende, quantas parcelas vencidas
    e o maior atraso);
  - **só vira `breached` por ação explícita** (`POST /api/v1/agreements/{id}/breach`), com motivo,
    auditada, e só quando a regra indica. As parcelas ficam como estão; nada muda sozinho.
  - Um acordo quebrado não pode ser cancelado (migration 0523).
- **Permissões** `erp.billing_rules.view` e `erp.billing_rules.manage` (migration 0524). A quebra usa
  `erp.agreements.manage`.
- **API:** `/api/v1/billing-rules` (GET e PUT) e `/api/v1/price-indexes` (GET, POST e `/import`).

## Consequências

- O que fazer depois da quebra (cobrança judicial, AP-039; novo acordo; protesto) fica para quando a
  política for definida.
- O índice precisa ser lançado todo mês. Enquanto faltar, a correção sai menor e o aviso aparece.
- A frente de layout monta a tela da regra por condomínio e a dos índices, mostra os encargos na
  inadimplência, preenche a sugestão no formulário do acordo e mostra o botão de quebra.
