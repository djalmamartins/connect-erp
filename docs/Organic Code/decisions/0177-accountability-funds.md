# 0177 — Fundos na prestação de contas mensal

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0166 (prestação de contas, AP-101), 0169 (lançamentos patrimoniais), 0176 (saldo inicial no
  Livro Razão)

## Contexto

A prestação de contas mensal (AP-101) não mostrava o fundo de reserva nem os outros fundos. Trazia balancete, DRE,
bancos, inadimplência e fechamento.

Mesmo assim, o morador e o conselho perguntam pelo fundo: quanto havia, quanto entrou (a apropriação da taxa de fundo),
quanto saiu (o uso numa obra) e quanto sobrou. Esses números já existiam no Livro Razão e no Balanço, mas não chegavam
ao pacote do mês.

## Decisão

O pacote ganha a seção `funds`. Ela é montada a partir do Livro Razão do mês (`AccountingReports::ledger`, base
competência, contas do grupo 8), sem SQL próprio.

### Quais contas entram

Só as contas de passivo lançadas à mão:

- o fundo de reserva (8.2);
- outros fundos (8.3);
- as contas 8.x próprias do condomínio.

Contas a pagar (8.1) fica de fora, porque é calculada.

### O que vem por fundo

- saldo anterior;
- entradas: a crédito, como a apropriação ou uma importação;
- saídas: a débito, como o uso do fundo ou um estorno;
- saldo final;
- os movimentos: data, descrição, origem, entrada, saída e saldo.

### Totais e coerência

- O `summary` ganha `funds_opening_cents` e `funds_closing_cents`.
- Como a seção vem do Razão, os números batem com o Razão e com o Balanço no último dia do mês, inclusive o saldo
  inicial dentro do mês (ADR 0176).
- O `truncated` do pacote passa a considerar também o Razão.

## Consequências

- **Testes:**
  - `AccountingCoherenceTest` confere no ano de exemplo:
    - em outubro, a apropriação de 500,00 e o uso de 200,00 dão saldo de 300,00, igual ao Balanço;
    - em dezembro, os saldos da 8.2 e da 8.3 batem com o Balanço;
    - o lançamento errado entra e sai pelo estorno.
  - `AccountabilityDeliveriesOpeningsTest` confere a seção vazia quando o plano não tem conta de fundo.
- **Contrato da API:** o `GET /api/v1/accountability` ganha `funds` e os dois totais. Nada foi removido.
- **Sem migration, rota ou tela.** A tela da prestação de contas pode mostrar a seção quando a frente de layout
  quiser.
