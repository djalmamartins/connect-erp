---
codigo: AP-162
titulo: Configurar saldo inicial (centro de custo)
resumo: Saldo de partida de cada centro de custo do condomínio numa data, para o relatório de centros de custo começar do valor certo.
categoria: configuracoes
rota: /configuracoes/saldo-inicial-centro-de-custo
menu: Configurações > Financeiro e parâmetros > Saldo inicial (centro de custo)
icone: options-outline
situacao: funciona
relacionados: relatorio-de-centros-de-custo
palavras: saldo inicial (centro de custo), configurações, AP-162, implantação
rascunho: nao
---

## Para que serve

Quando um condomínio chega à Connect com saldo em algum centro de custo (um fundo de obras, por exemplo), o saldo inicial registra esse valor numa data. Cada centro de custo tem um saldo inicial por condomínio.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Saldo inicial (centro de custo)]].

```tela saldo-inicial-centro-de-custo
rota: /configuracoes/saldo-inicial-centro-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Saldo inicial (centro de custo)
1: menu | Saldo inicial (centro de custo) no menu lateral
2: main form[data-opening-form] | Formulário do saldo inicial
3: main table thead | Saldos já lançados
```

>! O saldo inicial é de **um condomínio**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como lançar

1. Escolha o **Centro de custo** (os do padrão da Connect e os do condomínio aparecem).
2. Informe a **Data do saldo** (até hoje) e o **Valor**. Use o sinal de menos para saldo devedor, ex.: -1.500,00.
3. Se quiser, escreva uma **Observação**.
4. Clique em {{Salvar saldo inicial}}.

Gravar de novo o mesmo centro de custo substitui o saldo anterior. Toda gravação fica na auditoria, com o valor de antes.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; lançar, **erp.settings.manage**. Cada pessoa só vê os condomínios vinculados à própria conta.
