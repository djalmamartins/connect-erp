---
codigo: AP-032
titulo: Faturas
resumo: Cobrança de cada tipo de fatura das unidades ativas do condomínio. Prepare, confira os valores por unidade e emita para gerar as contas a receber.
categoria: financeiro
rota: /financeiro/faturas
menu: Financeiro > Contas e pagamentos > Faturas
icone: list-outline
relacionados: boletos, contas-a-receber, acordos, fechamento-mensal
situacao: funciona
palavras: faturas, financeiro, AP-032, rateio, fração ideal, igualitário, percentual, vagas, bloco, torre, personalizado
manual: sim
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Faturas]].

```tela faturas
rota: /financeiro/faturas
condominio: residencial-jardim-das-acacias
legenda: Tela Faturas
1: menu | Faturas no menu lateral
2: main :is(a,button):has-text("Preparar fatura") | Botão {{Preparar fatura}}
3: main form[method=get] | Filtros: Competência, Situação
4: main table thead | Lista com Competência, Tipo, Valor e Situação
```

## Preparar a fatura do mês

A fatura é preparada para as unidades que participam do rateio, todas de uma vez. Nada é cobrado ainda: você confere unidade por unidade e só depois emite.

1. Confira o condomínio na barra superior.
2. Clique em {{Preparar fatura}}.
3. Escolha o **Tipo de fatura** (por exemplo, taxa condominial), a **Competência** e o **Vencimento**.
4. Em **Cálculo**, escolha a forma de rateio (veja a lista abaixo).
5. Em **Quem participa**, escolha todas as unidades, só alguns blocos ou torres, ou só as unidades que você marcar.
6. Informe o valor. Deixe vazio para usar o valor padrão do tipo.
7. Clique em {{Preparar}}.

```tela faturas-preparar
rota: /financeiro/faturas
condominio: residencial-jardim-das-acacias
legenda: Preparar a fatura de todas as unidades
antes: clicar main :is(a,button):has-text("Preparar fatura")
antes: esperar 500
recorte: dialog[open]
1: #inv-type | Tipo de fatura
2: #inv-competence | Mês de competência
3: #inv-due | Vencimento
4: #inv-method | Como o valor é calculado
5: #inv-amount | Valor por unidade (vazio = padrão do tipo)
6: dialog[open] button[type=submit] | Botão {{Preparar}}
```

> Preparar de novo a mesma competência e o mesmo tipo não duplica a fatura: o sistema mostra a que já existe.

## Formas de rateio

| Cálculo | Como divide | O que informar |
|---|---|---|
| Valor fixo por unidade (igualitário) | Cada unidade paga o mesmo valor | O valor de uma unidade |
| Total dividido pelo número de unidades | O total em partes iguais entre as que participam | O total |
| Rateio pela fração ideal | Pela fração ideal de cada unidade | O total |
| Percentual definido | Pelo **Percentual definido no rateio** do cadastro da unidade, que precisa somar 100% | O total |
| Pelas vagas de garagem | Pelo número de vagas ativas da unidade em [[Condomínios > Áreas Vinculáveis]]; quem não tem vaga não paga | O total |
| Personalizado | Valor de cada unidade, ou o total e o percentual de cada uma (somando 100%) | Na lista de unidades que aparece |

- O centavo que sobra da divisão vai para as unidades com a maior parte, e a soma sempre fecha com o total.
- A unidade marcada como **isenta de rateio** fica fora de "todas as unidades" e de "blocos". Escolhida uma a uma, ela entra.
- Em **Só os blocos ou torres escolhidos**, escreva os nomes separados por vírgula, iguais ao campo **Bloco ou torre** do cadastro da unidade. Para ratear a despesa de uma torre, prepare uma fatura de outro tipo só para ela.
- O consumo individual (água, gás, energia) não é feito aqui: lance as leituras em [Leituras e consumo](artigo:leituras) e cobre a competência por lá.
- Se os percentuais não somarem 100%, faltar a fração ou o valor de alguma unidade, o sistema não prepara e diz o que falta.

> A forma de rateio, quem participa e o valor informado ficam gravados na fatura e no histórico.

## Conferir e emitir

Na lista, abra a fatura preparada. Confira o valor de cada unidade, ajuste o que for preciso e clique em **Emitir**. A emissão gera uma conta a receber para cada unidade, e a partir daí você pode [emitir os boletos](artigo:boletos).

>! Depois de emitida, a fatura não volta a ser rascunho. Para corrigir uma unidade, use [Lançar desconto](artigo:lancar-desconto) ou cancele a conta a receber dela.

>> Tudo sobre a cobrança: [Boletos](artigo:boletos) e [Acordos](artigo:acordos).

## Gerar todos os boletos de uma vez

Depois de emitir a fatura, clique em **Gerar boletos** na linha dela. Escolha a conta bancária do condomínio (por exemplo, a do Banco Inter) e confirme. O sistema emite um boleto para cada unidade com a conta a receber em aberto e ainda sem boleto, mostrando o andamento até terminar.

- Unidade sem CPF/CNPJ do responsável ou sem endereço completo do condomínio fica como **recusada**, com o motivo, e não para as outras. Corrija o cadastro e emita o boleto dela em [[Cobrança > Boletos]].
- Unidade que já tem boleto não ganha outro.
- Os boletos aparecem em [[Cobrança > Boletos]], com Pix e PDF quando o banco oferece.

## Boleto pago com atraso

Quando o morador paga depois do vencimento, o banco cobra a multa e os juros configurados em [Índices e regra de cobrança](artigo:indices). No retorno do banco, a conta da unidade é baixada pelo valor original e a diferença entra como receita **Multa e juros por atraso** (conta **1.03** do plano de contas) no mês em que foi paga.

- A competência da conta pode já estar fechada: o pagamento atrasado entra no mês do pagamento, sem reabrir o mês antigo.
- O mês do pagamento precisa estar aberto. Se estiver fechado, o retorno vai para conferência.
- Valor acima da multa e dos juros (com até R$ 1,00 de arredondamento do banco), ou pago a mais no prazo, também vai para conferência.

## Como usar

Os botões no alto da tela são {{Preparar fatura}}.

1. Ajuste os filtros (Competência e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Competência**
- **Tipo**
- **Valor**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

