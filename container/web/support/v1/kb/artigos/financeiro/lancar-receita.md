---
codigo: AP-025
titulo: Lançar uma receita
resumo: Lança uma receita do condomínio (aluguel do salão, rendimento) ou cobrada das unidades, com rateio, valor de cada unidade, fundo de reserva, cobrar de e repetição. A taxa condominial do mês sai pelas Faturas.
categoria: financeiro
rota: /financeiro/lancar-receita
menu: Financeiro > Lançamentos > Lançar Receita
icone: create-outline
situacao: funciona
relacionados: contas-a-receber, faturas, cobrancas-extras, lancar-despesa, lancar-desconto
palavras: lançar receita, financeiro, AP-025
rascunho: nao
---

Lança uma receita do condomínio (aluguel do salão, rendimento) ou cobrada das unidades, com rateio, valor de cada unidade, fundo de reserva, cobrar de e repetição. A taxa condominial do mês sai pelas Faturas.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Receita]].

```tela lancar-receita
rota: /financeiro/lancar-receita
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Receita
1: menu | Lançar Receita no menu lateral
2: main form[data-revenue-form] | Dados da receita
```

## Como lançar

1. Escolha o condomínio na barra superior.
2. Preencha **Descrição do lançamento**, **Conta da receita**, **Vencimento**, **Valor** e **Incluir na fatura de** (o mês de competência). **Centro de custo**, **Conta relacionada** e **Documento** são opcionais. A **Conta relacionada** liga a receita à conta que ela cobre (por exemplo, "Rateio de obra" ligado à despesa de obras); ela aparece na ficha de cada conta gerada e pode ser trocada depois.
3. Em **Rateio**, escolha:
   - **Sem rateio (receita do condomínio)**: sai uma conta a receber sem unidade.
   - **Cobrar de todas as unidades**: todas as unidades ativas, menos as isentas de rateio.
   - **Cobrar das unidades escolhidas**: marque as unidades na tabela (a isenta pode ser marcada).
4. Com rateio, escolha o **Cálculo**: valor fixo por unidade, total dividido igualmente, total pela fração ideal ou **Valor de cada unidade** (a tabela abre um campo de valor por unidade).
5. Em **Cobrar de**, deixe o responsável pela cobrança da unidade ou escolha o proprietário ou o inquilino. Se a unidade não tiver a pessoa no papel escolhido, vale o responsável.
6. Para o fundo de reserva, marque **Incluir taxa de fundo de reserva**, informe o percentual (até 30%) e a conta do fundo. Sai uma conta a mais por unidade, com o percentual sobre o valor dela.
7. Para repetir, marque **Repetir lançamento** e escolha quantos meses (até 24). Cada mês sai com a competência e o vencimento do mês seguinte; com **Mostrar "(1/N)" na descrição**, a descrição ganha a parcela. Para continuar uma série já começada, preencha **Índice inicial da repetição** e **Total de repetições**: com índice 4, total 10 e 7 meses, as descrições vão de (4/10) a (10/10).
8. Clique em {{Salvar}}. As contas aparecem em [Contas a receber](artigo:contas-a-receber) e, com rateio, em [Cobranças extras](artigo:cobrancas-extras), onde podem ser canceladas juntas.

```tela lancar-receita-form
rota: /financeiro/lancar-receita
condominio: residencial-jardim-das-acacias
legenda: Nova receita
recorte: main form[data-revenue-form]
1: main form[data-revenue-form] [name="description"] | Descrição do lançamento
2: main form[data-revenue-form] [name="chart_account_id"] | Conta da receita
3: main form[data-revenue-form] [name="apportion"] | Rateio
4: main form[data-revenue-form] [name="payer"] | Cobrar de
5: main form[data-revenue-form] button:has-text("Salvar") | Botão {{Salvar}}
```

>! Se um dos meses estiver fechado no fechamento mensal, nada é gravado. Reabra o mês ou mude a competência.

## Anexos

No campo **Anexos**, escolha até 5 arquivos (PDF, imagem, documento ou planilha, até 20 MB cada) e o **Tipo do anexo**. Ao salvar, cada arquivo vai para todas as contas geradas pelo lançamento, guardado uma vez só. Se um envio falhar, as contas já estão gravadas e o aviso diz qual arquivo faltou; anexe-o depois no quadro de anexos do lançamento.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. Lançar exige a permissão de alterar lançamentos; ratear entre as unidades exige também a de lançar cobranças. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Contas a receber](artigo:contas-a-receber), [Faturas](artigo:faturas) e [Lançar uma despesa](artigo:lancar-despesa).
