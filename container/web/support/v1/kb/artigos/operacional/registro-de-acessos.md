---
codigo: AP-133
titulo: Registro de acessos da portaria
resumo: Entradas e saídas registradas pela portaria: visitantes, prestadores, entregadores e moradores, com data e hora.
categoria: operacional
rota: /operacional/registro-de-acessos
menu: Operacional > Portaria > Registro de Acessos
icone: list-outline
situacao: funciona
relacionados: previsao-de-visita, achados-e-perdidos, gerenciamento, entregas
palavras: registro de acessos, operacional, AP-133
rascunho: nao
---

Entradas e saídas registradas pela portaria: visitantes, prestadores, entregadores e moradores, com data e hora.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Registro de Acessos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela registro-de-acessos
rota: /operacional/registro-de-acessos
condominio: residencial-jardim-das-acacias
legenda: Tela Registro de Acessos
1: menu | Registro de Acessos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo acesso") | Botão {{Novo acesso}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Tipo e Situação
```

## Como cadastrar

1. Clique em {{Novo acesso}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Pessoa**, **Tipo**, **Entrada**, **Hora** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela registro-de-acessos-novo-acesso
rota: /operacional/registro-de-acessos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo acesso
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="person_name"] | Pessoa (obrigatório)
3: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
4: main form:not([method=get]) [name="entered_on"] | Entrada (obrigatório)
5: main form:not([method=get]) [name="entered_time"] | Hora (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade**: Unidade cadastrada em Condomínios › Unidades.
- **Pessoa** (obrigatório).
- **Documento (CPF ou RG)**: Exemplo: CPF ou RG.
- **Tipo** (obrigatório): Opções: Visitante, Prestador, Entregador e Morador.
- **Placa**: Exemplo: ABC1D23.
- **Entrada** (obrigatório).
- **Hora** (obrigatório).
- **Saída**: No mesmo dia.
- **Portaria**.
- **Visita prevista**: Opções: Nenhuma opção cadastrada.
- **Observações**.
- **Situação** (obrigatório): Opções: Registrado e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Tipo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Registro de acessos**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Visitas previstas](artigo:previsao-de-visita), [Achados e perdidos](artigo:achados-e-perdidos) e [Portarias](artigo:gerenciamento).
