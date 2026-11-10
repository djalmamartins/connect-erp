---
codigo: AP-015
titulo: Cadastrar e gerenciar contatos Úteis
resumo: Telefones úteis para moradores e portaria. Sem condomínio, o contato vale para todos.
categoria: cadastros
rota: /cadastros/contatos-uteis
menu: Cadastros > Contatos Úteis
icone: list-outline
situacao: funciona
palavras: contatos úteis, cadastros, AP-015
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Contatos Úteis]].

```tela contatos-uteis
rota: /cadastros/contatos-uteis
condominio: residencial-jardim-das-acacias
legenda: Tela Contatos Úteis
1: menu | Contatos Úteis no menu lateral
2: main :is(a,button):has-text("Novo contato") | Botão {{Novo contato}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Serviço, Situação
4: main table thead | Lista com Nome, Condomínio, Serviço, Telefone e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo contato}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela contatos-uteis-novo
rota: /cadastros/contatos-uteis/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de contato
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: vale para todos os condomínios. Só aparecem os condomínios que a sua conta pode ver.
- **Serviço** (obrigatório): opções: Emergência, Encanador, Eletricista, Chaveiro, Dedetização, Gás, Concessionária e Outro.
- **Nome** (obrigatório).
- **Telefone** (obrigatório).
- **E-mail**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

