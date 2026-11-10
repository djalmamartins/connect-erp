---
codigo: AP-109
titulo: Gráfico de uso das áreas comuns
resumo: Reservas não canceladas por área comum e mês. As oito áreas mais usadas aparecem; as demais somam em "Outras".
categoria: relatorios
rota: /relatorios/grafico-de-areas-comuns
menu: Relatórios > Gráficos > Gráfico de áreas comuns
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-financeiro-por-conta, grafico-de-inadimplencia, grafico-financeiro, grafico-de-consumo-geral
palavras: gráfico de áreas comuns, relatórios, AP-109
rascunho: nao
---

Reservas não canceladas por área comum e mês. As oito áreas mais usadas aparecem; as demais somam em "Outras".

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de áreas comuns]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-de-areas-comuns
rota: /relatorios/grafico-de-areas-comuns
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de áreas comuns
1: menu | Gráfico de áreas comuns no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **MÊS**, **Salão de festas** e **Churrasqueira**.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico financeiro por conta bancária](artigo:grafico-financeiro-por-conta), [Gráfico de inadimplência](artigo:grafico-de-inadimplencia) e [Gráfico financeiro (caixa)](artigo:grafico-financeiro).
