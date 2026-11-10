---
codigo: AP-089
titulo: Relatório de condomínios
resumo: Os condomínios do seu escopo com CNPJ, cidade, situação e quantas unidades, pessoas e contas bancárias têm.
categoria: relatorios
rota: /relatorios/relatorio-de-condominios
menu: Relatórios > Gerais > Relatório de condomínios
icone: document-text-outline
situacao: funciona
relacionados: listagem-de-presencas, relatorio-de-ocorrencias, listagem-de-pessoas, administracao
palavras: relatório de condomínios, relatórios, AP-089
rascunho: nao
---

Os condomínios do seu escopo com CNPJ, cidade, situação e quantas unidades, pessoas e contas bancárias têm.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de condomínios]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-condominios
rota: /relatorios/relatorio-de-condominios
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de condomínios
1: menu | Relatório de condomínios no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main table thead | Colunas da lista
```

## O que o relatório mostra

Colunas: **Condomínio**, **Cnpj**, **Cidade**, **UF**, **Situação**, **Unidades**, **Pessoas** e **Contas bancárias**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Listagem de presenças](artigo:listagem-de-presencas), [Relatório de ocorrências](artigo:relatorio-de-ocorrencias) e [Listagem de pessoas](artigo:listagem-de-pessoas).
