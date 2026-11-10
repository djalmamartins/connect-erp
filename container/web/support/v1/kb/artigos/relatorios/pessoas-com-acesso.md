---
codigo: AP-098
titulo: Pessoas com acesso aos condomínios
resumo: Quem tem acesso a cada condomínio por concessão, com a situação e a data em que o acesso foi dado.
categoria: relatorios
rota: /relatorios/pessoas-com-acesso
menu: Relatórios > Gerais > Pessoas com Acesso
icone: document-text-outline
situacao: funciona
relacionados: logs, boletos-enviados, relatorio-de-enderecos, relatorios-enviados
palavras: pessoas com acesso, relatórios, AP-098
rascunho: nao
---

Quem tem acesso a cada condomínio por concessão, com a situação e a data em que o acesso foi dado.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Pessoas com Acesso]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela pessoas-com-acesso
rota: /relatorios/pessoas-com-acesso
condominio: residencial-jardim-das-acacias
legenda: Tela Pessoas com Acesso
1: menu | Pessoas com Acesso no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main table thead | Colunas da lista
```

## O que o relatório mostra

Colunas: **Condomínio**, **Nome**, **E-mail**, **Situação** e **Concedido em**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

> Na tela: *Dados pessoais: documento, e-mail, telefone e IP aparecem mascarados, e a exportação fica registrada na auditoria.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Logs de auditoria](artigo:logs), [Boletos enviados](artigo:boletos-enviados) e [Relatório de endereços dos condomínios](artigo:relatorio-de-enderecos).
