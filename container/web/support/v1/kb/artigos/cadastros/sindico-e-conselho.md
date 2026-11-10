---
codigo: AP-014
titulo: Cadastrar e gerenciar síndico e conselho
resumo: Síndico, subsíndico e conselho fiscal e consultivo de cada condomínio, com o mandato.
categoria: cadastros
rota: /cadastros/sindico-e-conselho
menu: Cadastros > Síndico e conselho
icone: list-outline
situacao: funciona
palavras: síndico e conselho, cadastros, AP-014
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Síndico e conselho]].

```tela sindico-e-conselho
rota: /cadastros/sindico-e-conselho
condominio: residencial-jardim-das-acacias
legenda: Tela Síndico e conselho
1: menu | Síndico e conselho no menu lateral
2: main :is(a,button):has-text("Novo membro") | Botão {{Novo membro}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Cargo, Situação
4: main table thead | Lista com Nome ou razão social, Condomínio, Cargo, Fim do mandato, Telefone e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo membro}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela sindico-e-conselho-novo
rota: /cadastros/sindico-e-conselho/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de membro
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Cargo** (obrigatório): opções: Síndico, Subsíndico, Conselheiro fiscal, Conselheiro consultivo e Suplente.
- **Nome ou razão social** (obrigatório).
- **CPF/CNPJ**: cNPJ para síndico profissional. CPF ou CNPJ; o sistema confere os dígitos.
- **Unidade**: unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Unidade (texto)**: vazio para síndico profissional.
- **Início do mandato** (obrigatório): no formato dia/mês/ano.
- **Fim do mandato** (obrigatório): no formato dia/mês/ano.
- **E-mail**.
- **Telefone**.
- **Observações**: ex.: ata da assembleia que elegeu.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

