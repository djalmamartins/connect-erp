---
codigo: AP-131
titulo: Cadastrar e gerenciar gerenciamento
resumo: Portarias de cada condomínio: presencial, remota ou híbrida, turnos e responsável.
categoria: operacional
rota: /operacional/gerenciamento
menu: Operacional > Portaria > Gerenciamento
icone: list-outline
situacao: funciona
palavras: gerenciamento, operacional, AP-131
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Gerenciamento]].

```tela gerenciamento
rota: /operacional/gerenciamento
condominio: residencial-jardim-das-acacias
legenda: Tela Gerenciamento
1: menu | Gerenciamento no menu lateral
2: main :is(a,button):has-text("Nova portaria") | Botão {{Nova portaria}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main .erp-card | Portarias
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova portaria}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela gerenciamento-novo
rota: /operacional/gerenciamento/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de portaria
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Nome** (obrigatório): ex.: Portaria principal.
- **Tipo** (obrigatório): opções: Presencial, Remota e Híbrida.
- **Turnos**.
- **Responsável**.
- **Telefone**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

