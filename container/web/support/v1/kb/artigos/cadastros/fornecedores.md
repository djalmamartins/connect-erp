---
codigo: AP-010
titulo: Cadastrar e gerenciar fornecedores
resumo: Fornecedores e prestadores de serviço da Connect, usados por todos os condomínios.
categoria: cadastros
rota: /cadastros/fornecedores
menu: Cadastros > Fornecedores
icone: list-outline
situacao: funciona
palavras: fornecedores, cadastros, AP-010
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cadastros > Fornecedores]].

```tela fornecedores
rota: /cadastros/fornecedores
condominio: residencial-jardim-das-acacias
legenda: Tela Fornecedores
1: menu | Fornecedores no menu lateral
2: main :is(a,button):has-text("Novo fornecedor") | Botão {{Novo fornecedor}}
3: main form[method=get] | Filtros: Pesquisar, Categoria, Situação
4: main table thead | Lista com Razão social ou nome, Cnpj/cpf, Categoria, Telefone e Situação
```

## Como cadastrar

1. Clique em {{Novo fornecedor}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela fornecedores-novo
rota: /cadastros/fornecedores/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de fornecedor
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Razão social ou nome** (obrigatório).
- **CNPJ/CPF** (obrigatório): cPF ou CNPJ; o sistema confere os dígitos.
- **Nome fantasia**.
- **Categoria** (obrigatório): opções: Manutenção, Limpeza, Segurança, Elevadores, Jardinagem, Portaria, Concessionária (água, luz, gás) e Outros.
- **E-mail**.
- **Telefone**.
- **Banco (código)**.
- **Agência**.
- **Conta**.
- **Chave Pix**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

