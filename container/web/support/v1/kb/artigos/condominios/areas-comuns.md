---
codigo: AP-008
titulo: Cadastrar e gerenciar áreas Comuns
resumo: Salão de festas, churrasqueira, academia e demais áreas que os moradores podem reservar.
categoria: condominios
rota: /condominios/areas-comuns
menu: Condomínios > Áreas Comuns
icone: list-outline
situacao: funciona
palavras: áreas comuns, condomínios, AP-008
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Condomínios > Áreas Comuns]].

```tela areas-comuns
rota: /condominios/areas-comuns
condominio: residencial-jardim-das-acacias
legenda: Tela Áreas Comuns
1: menu | Áreas Comuns no menu lateral
2: main :is(a,button):has-text("Nova área comum") | Botão {{Nova área comum}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Reserva, Situação
4: main table thead | Lista com Nome da área, Condomínio, Reserva, Capacidade (pessoas), Taxa de reserva e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova área comum}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela areas-comuns-novo
rota: /condominios/areas-comuns/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de área comum
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Nome da área** (obrigatório).
- **Reserva** (obrigatório): opções: Exige aprovação, Livre, Bloqueada e Não reservável.
- **Capacidade (pessoas)**.
- **Taxa de reserva**: em reais, por exemplo 1.250,00.
- **Antecedência mínima (dias)**.
- **Antecedência máxima (dias)**.
- **Abre às**.
- **Fecha às**.
- **Regras de uso**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

