---
codigo: AP-047
titulo: Relatório de acordos
resumo: Acordos criados no período, com as parcelas pagas, o que segue em aberto e o que venceu. Acordo cancelado aparece, mas fica fora das somas.
categoria: relatorios
rota: /relatorios/relatorio-de-acordos
menu: Relatórios > Financeiros > Relatório de acordos
icone: document-text-outline
situacao: funciona
relacionados: consumo-unidade, taxas-de-acordos, consumo-geral, relatorio-de-centros-de-custo
palavras: relatório de acordos, relatórios, AP-047
rascunho: nao
---

Acordos criados no período, com as parcelas pagas, o que segue em aberto e o que venceu. Acordo cancelado aparece, mas fica fora das somas.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de acordos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-acordos
rota: /relatorios/relatorio-de-acordos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de acordos
1: menu | Relatório de acordos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Situação
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Situação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros no período escolhido.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de consumo por unidade](artigo:consumo-unidade), [Taxas de acordos](artigo:taxas-de-acordos) e [Relatório de consumo geral](artigo:consumo-geral).
