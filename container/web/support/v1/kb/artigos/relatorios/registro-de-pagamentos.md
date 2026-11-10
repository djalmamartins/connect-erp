---
codigo: AP-074
titulo: Registro de pagamentos
resumo: Contas pagas no período, pela data do pagamento, com a conta bancária, o fornecedor e o documento.
categoria: relatorios
rota: /relatorios/registro-de-pagamentos
menu: Relatórios > Financeiros > Registro de Pagamentos
icone: document-text-outline
situacao: funciona
relacionados: saldos-de-contas, relatorio-memoria-de-calculo, recebimentos-por-conta, relatorio-de-transferencias
palavras: registro de pagamentos, relatórios, AP-074
rascunho: nao
---

Contas pagas no período, pela data do pagamento, com a conta bancária, o fornecedor e o documento.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Registro de Pagamentos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela registro-de-pagamentos
rota: /relatorios/registro-de-pagamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Registro de Pagamentos
1: menu | Registro de Pagamentos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Natureza
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Natureza**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Natureza**, **Pago em**, **Vencimento**, **Descrição**, **Documento**, **Conta**, **Conta bancária**, **Fornecedor**, **Cpf/cnpj**, **Unidade** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Saldos das contas bancárias](artigo:saldos-de-contas), [Memória de cálculo dos encargos](artigo:relatorio-memoria-de-calculo) e [Recebimentos por conta](artigo:recebimentos-por-conta).
