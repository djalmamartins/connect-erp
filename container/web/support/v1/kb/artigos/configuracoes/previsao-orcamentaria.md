---
codigo: AP-163
titulo: Previsão orçamentária
resumo: Quanto o condomínio prevê receber e gastar em cada conta do plano, mês a mês, para comparar depois com o que foi lançado.
categoria: configuracoes
rota: /configuracoes/previsao-orcamentaria
menu: Configurações > Financeiro e parâmetros > Previsão Orçamentária
icone: options-outline
situacao: funciona
relacionados: relatorio-de-previsao-orcamentaria
palavras: previsão orçamentária, orçamento, configurações, AP-163
rascunho: nao
manual: sim
---

## Para que serve

A previsão orçamentária guarda o valor previsto de cada conta analítica do plano de contas, mês a mês, no ano. O [Relatório de previsão orçamentária](artigo:relatorio-de-previsao-orcamentaria) compara o previsto com o lançado.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Previsão Orçamentária]].

```tela previsao-orcamentaria
rota: /configuracoes/previsao-orcamentaria
condominio: residencial-jardim-das-acacias
legenda: Tela Previsão Orçamentária
1: menu | Previsão Orçamentária no menu lateral
2: main form.erp-filters | Ano da previsão
3: main form[data-budget-form] | Conta e os 12 meses
```

>! A previsão é de **um condomínio**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como preencher

1. Escolha o **Ano** e clique em {{Ver}}.
2. Em **Conta do plano**, escolha a conta analítica (receita ou despesa).
3. Preencha o valor de cada mês. Mês vazio vale zero. {{Repetir janeiro nos outros meses}} copia o valor de janeiro para os demais.
4. Clique em {{Salvar previsão}}. Os 12 meses da conta são gravados juntos.

Para corrigir uma conta, clique em {{Editar}} na linha dela: o formulário abre com os valores atuais.

>! Competência fechada não muda: se um dos meses já foi fechado e o valor dele mudou, o sistema recusa e avisa qual competência está fechada.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; gravar, **erp.settings.manage**. Toda gravação fica na auditoria.
