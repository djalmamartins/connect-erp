---
codigo: AP-096
titulo: Relatório de endereços dos condomínios
resumo: Endereço completo e contato de cada condomínio do seu escopo.
categoria: relatorios
rota: /relatorios/relatorio-de-enderecos
menu: Relatórios > Gerais > Relatório de Endereços
icone: document-text-outline
situacao: funciona
relacionados: mudancas, logs, pagamentos-pendentes, pessoas-com-acesso
palavras: relatório de endereços, relatórios, AP-096
rascunho: nao
---

Endereço completo e contato de cada condomínio do seu escopo.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de Endereços]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-enderecos
rota: /relatorios/relatorio-de-enderecos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de Endereços
1: menu | Relatório de Endereços no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main table thead | Colunas da lista
```

## O que o relatório mostra

Colunas: **Condomínio**, **CEP**, **Logradouro**, **Número**, **Complemento**, **Bairro**, **Cidade**, **UF**, **Telefone** e **E-mail**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de mudanças](artigo:mudancas), [Logs de auditoria](artigo:logs) e [Pagamentos pendentes](artigo:pagamentos-pendentes).
