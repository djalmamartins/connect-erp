---
codigo: AP-121
titulo: Cadastrar e gerenciar configurações
resumo: Regras de mudança de cada condomínio: dias, horário, antecedência mínima e taxa.
categoria: operacional
rota: /operacional/configuracoes
menu: Operacional > Rotina > Configurações
icone: list-outline
situacao: funciona
palavras: configurações, operacional, AP-121
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Configurações]].

```tela configuracoes
rota: /operacional/configuracoes
condominio: residencial-jardim-das-acacias
legenda: Tela Configurações
1: menu | Configurações no menu lateral
2: main :is(a,button):has-text("Nova configuração") | Botão {{Nova configuração}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Configurações de mudança
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova configuração}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela configuracoes-novo
rota: /operacional/configuracoes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de configuração
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Dias permitidos** (obrigatório): ex.: segunda a sábado.
- **Horário**: ex.: 8h às 17h.
- **Antecedência mínima (dias)**.
- **Taxa**: em reais, por exemplo 1.250,00.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

