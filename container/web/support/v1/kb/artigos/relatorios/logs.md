---
codigo: AP-097
titulo: Logs de auditoria
resumo: O registro de auditoria do ERP no período: quem fez o quê e quando. Só aparece para quem acessa todos os condomínios.
categoria: relatorios
rota: /relatorios/logs
menu: Relatórios > Gerais > Logs
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-enderecos, pessoas-com-acesso, mudancas, boletos-enviados
palavras: logs, relatórios, AP-097
rascunho: nao
---

O registro de auditoria do ERP no período: quem fez o quê e quando. Só aparece para quem acessa todos os condomínios.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Logs]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela logs
rota: /relatorios/logs
condominio: residencial-jardim-das-acacias
legenda: Tela Logs
1: menu | Logs no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Ação
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Ação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Dados pessoais: documento, e-mail, telefone e IP aparecem mascarados, e a exportação fica registrada na auditoria.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de endereços dos condomínios](artigo:relatorio-de-enderecos), [Pessoas com acesso aos condomínios](artigo:pessoas-com-acesso) e [Relatório de mudanças](artigo:mudancas).
