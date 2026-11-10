---
codigo: AP-085
titulo: Relatório de reservas
resumo: Reservas das áreas comuns no período, com a taxa e a situação.
categoria: relatorios
rota: /relatorios/relatorio-de-reservas
menu: Relatórios > Gerais > Relatório de reservas
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-unidades, listagem-de-pessoas, listagem-de-presencas, relatorio-de-condominios
palavras: relatório de reservas, relatórios, AP-085
rascunho: nao
---

Reservas das áreas comuns no período, com a taxa e a situação.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de reservas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-reservas
rota: /relatorios/relatorio-de-reservas
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de reservas
1: menu | Relatório de reservas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Situação
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Situação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros para os filtros escolhidos.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de unidades](artigo:relatorio-de-unidades), [Listagem de pessoas](artigo:listagem-de-pessoas) e [Listagem de presenças](artigo:listagem-de-presencas).
