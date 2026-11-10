---
codigo: CN-006
titulo: Lançamentos patrimoniais
resumo: Movimentos entre contas patrimoniais, como apropriar ou usar o fundo de reserva e ajustar o patrimônio líquido. Não entram na DRE e não mexem no banco.
categoria: financeiro
rota: /financeiro/lancamentos-patrimoniais
menu: Financeiro > Lançamentos > Lançamentos patrimoniais
icone: list-outline
situacao: funciona
relacionados: balanco-patrimonial, saldo-inicial-plano-de-contas, lancamentos
palavras: lançamentos patrimoniais, fundo de reserva, partida dobrada, estorno, patrimônio líquido, CN-006
rascunho: nao
---

## Para que serve

Registra o que acontece **dentro do patrimônio** do condomínio, sem receita nem despesa nova: separar parte do resultado para o fundo de reserva, usar o fundo para cobrir uma despesa já lançada, passar dinheiro de um fundo para outro ou ajustar o patrimônio líquido. Cada lançamento debita uma conta e credita outra com o mesmo valor (partida dobrada).

A receita da taxa de fundo e a despesa paga com o fundo continuam em [Lançamentos](artigo:lancamentos): elas entram na DRE e no banco. O lançamento patrimonial só muda o [Balanço Patrimonial](artigo:balanco-patrimonial).

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançamentos patrimoniais]].

```tela lancamentos-patrimoniais
rota: /financeiro/lancamentos-patrimoniais
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos patrimoniais
1: menu | Lançamentos patrimoniais no menu lateral
2: main form.erp-filters | Período: De e Até
3: main form[data-access-form] | Novo lançamento
4: main [data-patrimonial-import] | Importar planilha
```

>! Para lançar, escolha o condomínio na barra superior. Com "Todos os condomínios", a tela só lista.

## Como lançar

1. Preencha **Data** (até hoje), **Descrição** e **Valor**.
2. Escolha a **Conta de débito** e a **Conta de crédito**. Elas precisam ser diferentes.
3. Se quiser, indique a **Conta bancária** onde está o dinheiro do fundo e o **Nº do lançamento de origem**. Os dois são só referência.
4. Clique em {{Lançar}}.

### Exemplos

- **Apropriar o fundo de reserva:** débito em 9.2 Resultado do exercício, crédito em 8.2 Fundo de reserva.
- **Usar o fundo numa despesa já lançada:** débito em 8.2 Fundo de reserva, crédito em 9.2 Resultado do exercício.
- **Transferir entre fundos:** débito no fundo que diminui, crédito no fundo que aumenta.

No ativo, o débito aumenta a conta. No passivo e no patrimônio líquido, quem aumenta é o crédito.

> **Atenção:** 7.1 Disponível, 7.2 Contas a receber e 8.1 Contas a pagar são calculadas pelo balanço e não aparecem na lista de contas. O dinheiro entra e sai pelos lançamentos de receita e despesa.

## Importar uma planilha

Para lançar vários de uma vez, use o cartão **Importar planilha** (aparece com o condomínio escolhido e a permissão de importar).

1. Clique em **Baixar modelo CSV** e preencha uma linha por lançamento. As colunas obrigatórias são `data` (DD/MM/AAAA), `descricao`, `valor` (1.234,56), `conta_debito` e `conta_credito` (o código do plano, como 8.2). As opcionais são `conta_bancaria` (o apelido da conta, como está no cadastro) e `lancamento_origem` (o nº do lançamento).
2. Escolha o arquivo (CSV ou XLSX, até 2 MB) e clique em {{Ver prévia}}.
3. A prévia mostra cada linha como **Certa** ou **Erro**, com o motivo. Se houver erro, corrija a planilha e envie de novo.
4. Sem erros, clique em {{Gravar}}. A gravação é **tudo ou nada**: se um mês fechar no meio do caminho, nenhuma linha entra.

O mesmo arquivo não é gravado duas vezes no mesmo condomínio.

## Estornar

Lançamento não é apagado. Clique em {{Estornar}} na linha, escreva o motivo, confira a data e clique em {{Confirmar estorno}}. O sistema cria o lançamento invertido, marca o original como **Estornado** e os dois se anulam. Um estorno não pode ser estornado de novo.

Mês fechado no [Fechamento mensal](artigo:fechamento-mensal) não recebe lançamento nem estorno: reabra o mês antes.

## Quem pode usar

Ver exige acesso a **Lançamentos** e ao **Plano de contas**. Lançar e estornar exige também permissão para gerenciar lançamentos. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico.
