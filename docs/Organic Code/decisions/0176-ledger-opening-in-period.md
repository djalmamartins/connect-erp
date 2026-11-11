# 0176 — Saldo inicial dentro do período entra no Livro Razão

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0165 (Livro Razão), 0166 (saldo inicial do plano, AP-161), 0167 (Balanço) e 0172 (revisão, que
  aceitou esta diferença como risco)

## Contexto

O Livro Razão (AP-083) usava o saldo inicial do plano só quando a data dele era até o início do período. Um saldo com
data dentro do período ficava de fora e aparecia em `openings_ignored`, mas o Balanço Patrimonial contava esse mesmo
saldo. O resultado: num período que contém a data do saldo inicial, o Razão e o Balanço mostravam números diferentes
para a mesma conta. Isso acontece, por exemplo, no ano em que o condomínio começa a usar o sistema no meio do ano.

## Decisão

O saldo inicial com data dentro do período vira uma linha "Saldo inicial (AP-161)" na data dele, com
`source = 'opening'`.

### O valor da linha

- O valor é o ajuste que leva a conta ao saldo informado: o saldo informado menos o histórico que ele substitui.
- Esse histórico é tudo o que a conta acumulou naquele condomínio antes da data, pela mesma regra do saldo anterior:
  - **lançamentos em caixa:** os pagos antes da data;
  - **lançamentos em competência:** os de competência anterior ao mês da data;
  - **lançamentos patrimoniais:** os com data anterior, nas duas bases.

### Débito ou crédito

A linha vai a débito ou a crédito pelo lado em que a conta cresce:

- despesa e ativo crescem a débito;
- receita, passivo e patrimônio líquido crescem a crédito.

Um ajuste negativo vai ao outro lado. Assim, débitos e créditos continuam fechando com o saldo final.

### Ordem das linhas

- Na mesma data, o saldo inicial vem primeiro, depois os lançamentos e por fim os patrimoniais.
- Os lançamentos antes da data continuam visíveis no Razão, como histórico.

### O que fica de fora

- `openings_ignored` passa a listar só os saldos com data depois do fim do período.
- O campo novo `openings_in_period` lista as contas com saldo inicial dentro do período.

### Resultado

O saldo final da conta fica igual ao do Balanço: saldo inicial + movimentos depois da data. Com vários condomínios
no escopo, cada saldo inicial gera a sua linha, com o histórico do seu condomínio.

## Consequências

- **Testes:**
  - `AccountingCoherenceTest::testLedgerOpeningInsideThePeriodMatchesTheBalanceSheet` confere, nas bases competência
    e caixa:
    - que o Razão da 8.3 é igual ao Balanço;
    - que a receita com saldo menor que o histórico vai a débito;
    - que o ajuste leva a conta ao saldo informado;
    - que débitos e créditos fecham.
  - O `BalanceSheetTest` foi atualizado para a regra nova e continua conferindo o saldo com data depois do período.
- **Contrato da API:** a resposta do Razão ganha `openings_in_period` e linhas com `source = 'opening'`. O
  `openings_ignored` continua existindo, agora só com os saldos depois do período.
- **Sem migration, rota ou tela.** A tela do Razão mostra a linha como qualquer outra; se quiser destacar o saldo
  inicial, pode usar `source`.
