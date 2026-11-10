---
codigo: AP-037
titulo: Remessas de cobrança
resumo: Arquivos de remessa enviados ao banco com os boletos para registro.
categoria: cobranca
rota: /cobranca/remessas
menu: Cobrança > Remessas
icone: list-outline
situacao: exemplo
relacionados: boletos, arquivo-de-retorno, parcelas-de-acordo, retornos
palavras: remessas, cobrança, AP-037
rascunho: nao
---

Arquivos de remessa enviados ao banco com os boletos para registro.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Cobrança > Remessas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela remessas
rota: /cobranca/remessas
condominio: residencial-jardim-das-acacias
legenda: Tela Remessas
1: menu | Remessas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova remessa") | Botão {{Nova remessa}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até e Situação
5: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova remessa}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Condomínio** e **Conta bancária**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.

```tela remessas-nova-remessa
rota: /cobranca/remessas
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova remessa
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Nova remessa")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="ap-037-condominio"] | Condomínio (obrigatório)
2: dialog[open] [name="ap-037-conta-bancaria"] | Conta bancária (obrigatório)
3: dialog[open] button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Conta bancária** (obrigatório).

## Pesquisar e filtrar

Use os filtros no alto da lista: **Condomínio**, **De**, **Até** e **Situação**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Arquivo**, **Banco**, **Condomínio**, **Boletos**, **Gerada em** e **Situação**.

## Ações de cada linha

- **Ver** abre os detalhes do registro, sem alterar nada.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Boletos](artigo:boletos), [Importar o arquivo de retorno do banco](artigo:arquivo-de-retorno) e [Parcelas de acordo](artigo:parcelas-de-acordo).
