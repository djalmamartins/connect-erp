---
codigo: AP-103
titulo: Gráfico de contas
resumo: Valores por conta do plano em cada competência. As oito maiores aparecem; as demais somam em "Outras".
categoria: relatorios
rota: /relatorios/grafico-de-contas
menu: Relatórios > Gráficos > Gráfico de contas
icone: bar-chart-outline
situacao: funciona
relacionados: graficos-gerais, grafico-de-consumo-unidade, grafico-de-consumo-geral, grafico-financeiro
palavras: gráfico de contas, relatórios, AP-103
rascunho: nao
---

Valores por conta do plano em cada competência. As oito maiores aparecem; as demais somam em "Outras".

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de contas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-de-contas
rota: /relatorios/grafico-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de contas
1: menu | Gráfico de contas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Natureza
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Natureza**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **MÊS**, **2.01 pessoal**, **2.02 água e esgoto** e **2.03 manutenção**.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráficos gerais](artigo:graficos-gerais), [Gráfico de consumo da unidade](artigo:grafico-de-consumo-unidade) e [Gráfico de consumo geral](artigo:grafico-de-consumo-geral).
