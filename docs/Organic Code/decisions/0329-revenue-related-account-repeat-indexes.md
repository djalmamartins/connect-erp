# 0329 — Nova Receita: conta relacionada e índices da repetição

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026, cartão "Como seguimos com os itens que faltam da Nova Receita?" → "Rápidos agora, boleto único depois".
- Relacionadas: ADR 0313 (Nova Receita, modelo 8), 0323 (anexos na Nova Receita)

## Contexto

O modelo 8 (tela Nova Receita do sistema de referência, revisão de 15/11) tem "Conta relacionada", "Índice inicial da
repetição" e "Índice total de repetições do lançamento", que faltavam. A documentação de referência só mostra os campos.

## Decisão

1. **Conta relacionada** (INFERIDO, confirmado como leitura proposta ao Djalma): coluna opcional
   `erp_entries.related_account_id` (migration `20261116_2200`, FK para `erp_chart_accounts`), campo `ref` no motor de
   cadastros dos lançamentos, contas a pagar e a receber (analítica, de receita ou despesa, do padrão ou do condomínio).
   A Nova Receita grava a conta em todas as contas principais; as do fundo de reserva ficam sem ela. Só informativa:
   não muda saldo, fatura nem boleto. Os lançamentos fixos não têm o campo.
2. **Índices da repetição**: `repeat_start` (padrão 1) e `repeat_total` (padrão = inicial + meses - 1, nunca menor,
   até 999). A descrição sai "(inicial + i/total)", para continuar uma série (ex.: 4/10 a 10/10).
3. API `POST /api/v1/revenues` aceita `related_account_id`, `repeat_start` e `repeat_total` (OpenAPI).
4. PENDENTE, depois (escolha do Djalma): tipo da fatura = rateio no mesmo boleto da fatura da unidade.

## Consequências

- Testes: `RevenueLaunchTest::testRelatedAccountAndRepeatIndexes`; smoke da tela.
