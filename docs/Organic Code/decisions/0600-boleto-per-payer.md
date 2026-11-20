# 0600 — Boleto único por responsável (várias unidades do mesmo dono)

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0331 (boleto único da unidade), 0311 (Bloquear unificação de boletos), 0040 (regra de cobrança), 0035/0156 (boletos e Inter)

## Contexto
O ADR 0331 junta no boleto da fatura da unidade o rateio e o fundo dela. Em 20/11/2026 o Djalma escolheu, no cartão de
decisão, "Sim, juntar por proprietário": quem tem várias unidades no condomínio recebe um boleto só.

## Decisão
1. Chave `merge_owner_units` na regra de cobrança (`erp_billing_rules`, migration 20261120_1700), por condomínio ou no
   padrão da Connect, desligada por padrão. Tela Índices e regra de cobrança (AP-165) e `PUT /api/v1/billing-rules`;
   salvar sem o campo mantém o valor.
2. Ligada, `BankCharges::issue` soma no boleto da conta de fatura as contas da MESMA fatura das outras unidades cujo
   responsável pelo pagamento (o mesmo pagador do boleto: responsável financeiro, proprietário ou inquilino conforme o
   cadastro) tem o mesmo CPF/CNPJ (só dígitos), cada uma com o rateio e o fundo da própria unidade (ADR 0331).
   INFERIDO: "mesmo proprietário" = mesmo documento de quem paga; responsável sem CPF/CNPJ fica fora.
3. Unidade com "Bloquear unificação de boletos" fica fora, dos dois lados.
4. O lote "Gerar boletos" da fatura não conta como pendente a conta que já entrou num boleto ativo de outra unidade
   (senão o lote nunca chegaria a remaining = 0).
5. Baixa, travas, cancelamento e retorno seguem o ADR 0331 (link `erp_bank_charge_entries` e `BankCharges::COVERAGE`).

## Consequências
- Teste `BankChargeBundleTest::testUnitsOfTheSamePayerShareOneBoletoWhenTheRuleIsOn`.
- PENDENTE: conferir no sandbox do Inter, junto com o ADR 0331 (sandbox fora em 20/11/2026).
- Carta de cobrança e app do morador mostram o boleto pela conta principal; a conta da outra unidade aparece pelo link.
