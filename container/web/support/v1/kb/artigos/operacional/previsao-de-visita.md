---
codigo: AP-132
titulo: Visitas previstas
resumo: Visitas avisadas pela unidade com antecedência, para a portaria liberar na data sem precisar interfonar.
categoria: operacional
rota: /operacional/previsao-de-visita
menu: Operacional > Portaria > Previsão de Visita
icone: list-outline
situacao: funciona
relacionados: gerenciamento, registro-de-acessos, achados-e-perdidos, entregas
palavras: previsão de visita, operacional, AP-132
rascunho: nao
---

Visitas avisadas pela unidade com antecedência, para a portaria liberar na data sem precisar interfonar.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Previsão de Visita]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela previsao-de-visita
rota: /operacional/previsao-de-visita
condominio: residencial-jardim-das-acacias
legenda: Tela Previsão de Visita
1: menu | Previsão de Visita no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova visita prevista") | Botão {{Nova visita prevista}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Nova visita prevista}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Unidade**, **Visitante**, **Data** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela previsao-de-visita-nova-visita-prevista
rota: /operacional/previsao-de-visita/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova visita prevista
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade (obrigatório)
3: main form:not([method=get]) [name="visitor_name"] | Visitante (obrigatório)
4: main form:not([method=get]) [name="expected_on"] | Data (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade** (obrigatório): Unidade cadastrada em Condomínios › Unidades.
- **Visitante** (obrigatório).
- **Documento (CPF ou RG)**: Exemplo: CPF ou RG.
- **Data** (obrigatório).
- **Até**: Vazio = só no dia.
- **Autorizado por**.
- **Observações**.
- **Situação** (obrigatório): Opções: Prevista, Realizada e Cancelada.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Previsão de visita**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Portarias](artigo:gerenciamento), [Registro de acessos da portaria](artigo:registro-de-acessos) e [Achados e perdidos](artigo:achados-e-perdidos).
