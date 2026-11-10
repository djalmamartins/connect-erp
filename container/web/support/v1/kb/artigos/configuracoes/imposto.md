---
codigo: AP-164
titulo: Cadastrar e gerenciar imposto
resumo: Alíquotas de retenção para conferir as notas de serviço. Sem condomínio, vale para todos. Não calcula nem lança sozinho.
categoria: configuracoes
rota: /configuracoes/imposto
menu: Configurações > Financeiro e parâmetros > Imposto
icone: list-outline
situacao: funciona
palavras: imposto, configurações, AP-164
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Imposto]].

```tela imposto
rota: /configuracoes/imposto
condominio: residencial-jardim-das-acacias
legenda: Tela Imposto
1: menu | Imposto no menu lateral
2: main :is(a,button):has-text("Novo imposto") | Botão {{Novo imposto}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Imposto, Situação
4: main .erp-card | Impostos
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo imposto}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela imposto-novo
rota: /configuracoes/imposto/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de imposto
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: padrão da Connect. Só aparecem os condomínios que a sua conta pode ver.
- **Imposto** (obrigatório): opções: ISS, IRRF, INSS, PIS/COFINS/CSLL e Outro.
- **Nome** (obrigatório).
- **Alíquota (%)** (obrigatório).
- **Aplica-se a** (obrigatório): opções: Serviços, Materiais e Serviços e materiais.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

