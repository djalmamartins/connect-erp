---
codigo: AP-086
titulo: Relatório de unidades
resumo: As unidades ativas com tipo, andar, fração ideal, área privativa e quantas pessoas, medidores e contas vencidas cada uma tem.
categoria: relatorios
rota: /relatorios/relatorio-de-unidades
menu: Relatórios > Gerais > Relatório de unidades
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-reservas, listagem-de-pessoas, listagem-de-presencas, relatorio-de-condominios
palavras: relatório de unidades, relatórios, AP-086
rascunho: nao
---

As unidades ativas com tipo, andar, fração ideal, área privativa e quantas pessoas, medidores e contas vencidas cada uma tem.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de unidades]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-unidades
rota: /relatorios/relatorio-de-unidades
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de unidades
1: menu | Relatório de unidades no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main table thead | Colunas da lista
```

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Tipo**, **Andar**, **Fração ideal**, **Área privativa**, **Pessoas**, **Medidores** e **Contas vencidas**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de reservas](artigo:relatorio-de-reservas), [Listagem de pessoas](artigo:listagem-de-pessoas) e [Listagem de presenças](artigo:listagem-de-presencas).
