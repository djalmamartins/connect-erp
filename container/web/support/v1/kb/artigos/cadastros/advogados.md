---
codigo: AP-016
titulo: Cadastrar e gerenciar advogados
resumo: Advogados e escritórios parceiros para cobrança judicial e assessoria.
categoria: cadastros
rota: /cadastros/advogados
menu: Cadastros > Advogados
icone: list-outline
situacao: funciona
palavras: advogados, cadastros, AP-016
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Advogados]].

```tela advogados
rota: /cadastros/advogados
condominio: residencial-jardim-das-acacias
legenda: Tela Advogados
1: menu | Advogados no menu lateral
2: main :is(a,button):has-text("Novo advogado") | Botão {{Novo advogado}}
3: main form[method=get] | Filtros: Pesquisar, Situação
4: main table thead | Lista com Nome, Oab, Escritório, Telefone e Situação
```

## Como cadastrar

1. Clique em {{Novo advogado}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela advogados-novo
rota: /cadastros/advogados/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de advogado
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Nome** (obrigatório).
- **OAB** (obrigatório): uF e número, ex.: SP 123456.
- **CPF**: o sistema confere os dígitos do CPF.
- **Escritório**.
- **E-mail**.
- **Telefone**.
- **Honorários (%)**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

