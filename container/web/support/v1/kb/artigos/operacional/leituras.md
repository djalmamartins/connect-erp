---
codigo: AP-042
titulo: Lançar as leituras do mês
resumo: Lance as leituras do mês de todos os relógios de uma vez, confira o consumo e o valor de cada unidade e gere as contas a receber do consumo.
categoria: operacional
rota: /operacional/leituras
menu: Operacional > Leituras e consumo > Leituras
icone: list-outline
situacao: funciona
relacionados: importacao-de-leituras, relogios, tarifas, configuracoes
palavras: leituras, operacional, AP-042
rascunho: nao
---

Lance as leituras do mês de todos os relógios de uma vez, confira o consumo e o valor de cada unidade e gere as contas a receber do consumo.

## Onde fica

No menu lateral, abra [[Operacional > Leituras e consumo > Leituras]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela leituras
rota: /operacional/leituras
condominio: residencial-jardim-das-acacias
legenda: Tela Leituras
1: menu | Leituras no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Tarifas") | Botão {{Tarifas}}
3: main .erp-page-head__actions :is(a,button):has-text("Relógios") | Botão {{Relógios}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência e Tipo
```

## Outros botões da tela

- {{Relógios}}: abre o cadastro dos medidores.
- {{Tarifas}}: abre as faixas de tarifa usadas no cálculo do valor.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Competência** e **Tipo**.
Depois clique em {{Mostrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Relógios lidos**
- **Consumo**
- **Valor**
- **Cobradas**
- **Leituras de água · 11/2026**

> Leitura menor que a anterior é sinal de erro de digitação ou troca de relógio: confira antes de cobrar.

> Na tela: *Cadastre os relógios das unidades em Relógios ou escolha outro condomínio na barra superior.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Importar leituras de consumo](artigo:importacao-de-leituras), [Cadastrar relógios (medidores)](artigo:relogios) e [Tarifas de consumo](artigo:tarifas).
