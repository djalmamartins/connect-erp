---
codigo: AP-108
titulo: Gráfico financeiro por conta bancária
resumo: Saldo de cada mês (entradas menos saídas, com as transferências) por conta bancária.
categoria: relatorios
rota: /relatorios/grafico-financeiro-por-conta
menu: Relatórios > Gráficos > Gráfico financeiro por conta
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-de-inadimplencia, grafico-de-areas-comuns, grafico-financeiro, grafico-de-consumo-geral
palavras: gráfico financeiro por conta, relatórios, AP-108
rascunho: nao
---

Saldo de cada mês (entradas menos saídas, com as transferências) por conta bancária.

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico financeiro por conta]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-financeiro-por-conta
rota: /relatorios/grafico-financeiro-por-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico financeiro por conta
1: menu | Gráfico financeiro por conta no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **MÊS** e **Conta movimento**.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico de inadimplência](artigo:grafico-de-inadimplencia), [Gráfico de uso das áreas comuns](artigo:grafico-de-areas-comuns) e [Gráfico financeiro (caixa)](artigo:grafico-financeiro).
