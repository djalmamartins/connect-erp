# 0331 — Boleto único: o rateio entra no boleto da fatura da unidade

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026, cartão "Como seguimos com os itens que faltam da Nova Receita?" → "Rápidos agora, boleto
  único depois" (proposta: o rateio no mesmo boleto da fatura, como o "Tipo da fatura" do modelo 8).
- Relacionadas: ADR 0035 (boletos), 0156 (Inter), 0313 (Nova Receita), 0329 (conta relacionada)

## Contexto

Cada conta a receber tinha o próprio boleto: a unidade com taxa e rateio no mesmo mês recebia dois boletos. O modelo 8
tem "Incluir rateio na fatura de" e "Tipo da fatura" (INFERIDO: o rateio vai na fatura daquele tipo, num boleto só).

## Decisão

1. `erp_entries.bundle_invoice_type_id` (migration `20261116_2300`): na Nova Receita com rateio, "Tipo da fatura"
   marca as contas (e as do fundo de reserva). Padrão da tela: o primeiro tipo mensal; "Nenhum" = boleto próprio.
2. Ao emitir o boleto de uma conta de fatura (`BankCharges::issue`, também pelo lote "Gerar boletos"), entram junto
   as contas da mesma unidade e competência marcadas com o tipo daquela fatura, receitas ativas em aberto e sem boleto
   ativo. Valor do título = soma; vencimento = o da fatura; descrição "+ N cobranças". As contas a mais ficam em
   `erp_bank_charge_entries` (a principal continua em `erp_bank_charges.entry_id`).
3. Pedir o boleto de uma conta que entrou junto devolve o boleto da fatura. Rateio lançado depois do boleto da fatura
   emitido tem boleto próprio (o título registrado no banco não muda de valor).
4. Pagamento (retorno, webhook ou consulta): baixa todas as contas do boleto, tudo ou nada; se alguma estiver
   cancelada, baixada à mão ou em mês fechado, vira divergência e nada é baixado.
5. Travas por conta (cancelamento, reagendamento, acordo, troca de morador, estorno de baixa do banco, cobrança extra)
   passam a olhar `BankCharges::COVERAGE` (principal + as que entraram junto). Portal do morador mostra o boleto da
   fatura também na conta do rateio. A limpeza de contas de faturas canceladas trata o vínculo como ligação.
6. Lista de boletos mostra "com N cobrança(s) junto"; `GET /bank-charges/{id}` traz `bundled`. OpenAPI atualizado.

## Riscos e lacunas

- Testado com o sandbox interno; a soma no título do Inter e da Cora segue o mesmo `registerCharge` (valor total),
  sem mudança no adaptador. Conferir no sandbox do Inter antes de produção.
- Juros e multa por atraso são do título inteiro (regra de cobrança do condomínio), não por conta.
- Testes: `BankChargeBundleTest` (soma, vencimento, boleto do rateio = o da fatura, trava de cancelamento, baixa de
  todas, trava de estorno, rateio tardio com boleto próprio, sem tipo, tipo inválido); `PaymentLocksTest`.
