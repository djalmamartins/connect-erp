---
codigo: AP-161
titulo: Configurar saldo inicial (plano de contas)
resumo: O saldo de partida de cada conta analítica do plano de contas numa data, com a soma nas contas sintéticas.
categoria: configuracoes
rota: /configuracoes/saldo-inicial-plano-de-contas
menu: Configurações > Financeiro e parâmetros > Saldo inicial (plano de contas)
icone: options-outline
situacao: funciona
relacionados: saldo-inicial-centro-de-custo
palavras: saldo inicial, plano de contas, abertura, implantação, configurações, AP-161
rascunho: nao
---

## Para que serve

Guarda o saldo de partida de cada conta do plano de contas do condomínio numa data, por exemplo na implantação do condomínio no sistema. Só a **conta analítica** recebe saldo; a **sintética** mostra a soma das analíticas abaixo dela. O saldo por centro de custo fica em [Saldo inicial (centro de custo)](artigo:saldo-inicial-centro-de-custo).

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Saldo inicial (plano de contas)]].

```tela saldo-inicial-plano-de-contas
rota: /configuracoes/saldo-inicial-plano-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Saldo inicial (plano de contas)
1: menu | Saldo inicial (plano de contas) no menu lateral
2: main [data-erp-settings] | Lançar saldo inicial e a árvore dos saldos
```

>! O saldo inicial é de **um condomínio**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como lançar

1. Em **Conta analítica**, escolha a conta.
2. Informe a **Data do saldo** (até hoje) e o **Valor**. Use o sinal de menos para saldo devedor.
3. Se quiser, escreva uma observação e clique em {{Salvar saldo inicial}}.

Gravar de novo a mesma conta substitui o saldo anterior; o valor antigo fica na auditoria.

> **Atenção:** por enquanto o saldo inicial do plano ainda não entra no saldo anterior do Livro Razão.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; gravar, **erp.settings.manage**. Toda gravação fica na auditoria.
