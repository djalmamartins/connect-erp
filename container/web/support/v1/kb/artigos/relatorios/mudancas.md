---
codigo: AP-095
titulo: Relatório de mudanças
resumo: As mudanças agendadas no período.
categoria: relatorios
rota: /relatorios/mudancas
menu: Relatórios > Gerais > Mudanças
icone: document-text-outline
situacao: funciona
relacionados: pagamentos-pendentes, relatorio-de-enderecos, parecer, logs
palavras: mudanças, relatórios, AP-095
rascunho: nao
---

As mudanças agendadas no período.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Mudanças]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela mudancas
rota: /relatorios/mudancas
condominio: residencial-jardim-das-acacias
legenda: Tela Mudanças
1: menu | Mudanças no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros para os filtros escolhidos.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Pagamentos pendentes](artigo:pagamentos-pendentes), [Relatório de endereços dos condomínios](artigo:relatorio-de-enderecos) e [Parecer do conselho](artigo:parecer).
