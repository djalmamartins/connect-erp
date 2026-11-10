---
codigo: AP-106
titulo: Gráfico financeiro (caixa)
resumo: Recebido e pago por mês, pela data do pagamento, e o saldo de cada mês.
categoria: relatorios
rota: /relatorios/grafico-financeiro
menu: Relatórios > Gráficos > Gráfico financeiro
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-de-consumo-geral, grafico-de-inadimplencia, grafico-de-consumo-unidade, grafico-financeiro-por-conta
palavras: gráfico financeiro, relatórios, AP-106
rascunho: nao
---

Recebido e pago por mês, pela data do pagamento, e o saldo de cada mês.

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico financeiro]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-financeiro
rota: /relatorios/grafico-financeiro
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico financeiro
1: menu | Gráfico financeiro no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **MÊS**, **Recebido**, **Pago** e **Saldo do mês**.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico de consumo geral](artigo:grafico-de-consumo-geral), [Gráfico de inadimplência](artigo:grafico-de-inadimplencia) e [Gráfico de consumo da unidade](artigo:grafico-de-consumo-unidade).
