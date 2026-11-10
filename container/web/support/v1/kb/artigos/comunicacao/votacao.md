---
codigo: AP-017
titulo: Votações
resumo: Votações abertas para os condôminos, com a pergunta, a data de encerramento, os votos e a situação.
categoria: comunicacao
rota: /comunicacao/votacao
menu: Comunicação > Canais > Votação
icone: list-outline
situacao: exemplo
relacionados: assembleia-virtual, comunicados-gerais, cobranca, agendada
palavras: votação, comunicação, AP-017
rascunho: nao
---

Votações abertas para os condôminos, com a pergunta, a data de encerramento, os votos e a situação.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Comunicação > Canais > Votação]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela votacao
rota: /comunicacao/votacao
condominio: residencial-jardim-das-acacias
legenda: Tela Votação
1: menu | Votação no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova votação") | Botão {{Nova votação}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio e Pesquisar
5: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova votação}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Encerra em**, **Pergunta** e **Opções (uma por linha)**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.

```tela votacao-nova-votacao
rota: /comunicacao/votacao
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova votação
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Nova votação")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="ap-017-condominio"] | Condomínio (obrigatório)
2: dialog[open] [name="ap-017-encerra-em"] | Encerra em (obrigatório)
3: dialog[open] [name="ap-017-pergunta"] | Pergunta (obrigatório)
4: dialog[open] [name="ap-017-opcoes-uma-por-linha"] | Opções (uma por linha) (obrigatório)
5: dialog[open] button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Encerra em** (obrigatório).
- **Pergunta** (obrigatório).
- **Opções (uma por linha)** (obrigatório).

## Pesquisar e filtrar

Use os filtros no alto da lista: **Condomínio** e **Pesquisar**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Encerra em**, **Condomínio**, **Pergunta**, **Votos** e **Situação**.

## Ações de cada linha

- **Ver** abre os detalhes do registro, sem alterar nada.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Assembleia virtual](artigo:assembleia-virtual), [Enviar um comunicado por e-mail](artigo:comunicados-gerais) e [Enviar aviso de cobrança](artigo:cobranca).
