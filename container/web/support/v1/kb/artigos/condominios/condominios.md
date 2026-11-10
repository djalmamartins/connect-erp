---
codigo: AP-004
titulo: Cadastrar um condomínio
resumo: Aqui ficam os condomínios administrados pela Connect que a sua conta pode ver. O cadastro tem endereço, contato, síndico e dados fiscais. Depois de cadastrar, siga o checklist de chegada para deixar o condomínio pronto: unidades, pessoas, contas bancárias e cobrança.
categoria: condominios
rota: /condominios
menu: Condomínios > Condomínios
icone: list-outline
situacao: funciona
relacionados: painel-do-condominio, painel-da-unidade, unidades, areas-comuns
palavras: condomínios, AP-004, prédio, edifício, checklist, cnpj, cep
rascunho: nao
---

Aqui ficam os condomínios administrados pela Connect que a sua conta pode ver. O cadastro tem endereço, contato, síndico e dados fiscais. Depois de cadastrar, siga o checklist de chegada para deixar o condomínio pronto: unidades, pessoas, contas bancárias e cobrança.

## Onde fica

No menu lateral, abra [[Condomínios > Condomínios]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela condominios
rota: /condominios
condominio: residencial-jardim-das-acacias
legenda: Tela Condomínios
1: menu | Condomínios no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo condomínio") | Botão {{Novo condomínio}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo condomínio}}, no alto da lista.
2. Preencha os campos obrigatórios: **Nome**, **Razão social**, **Situação**, **CEP**, **Logradouro**, **Número**, **Bairro**, **Cidade** e **UF**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela condominios-novo-condominio
rota: /condominios/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo condomínio
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="name"] | Nome (obrigatório)
2: main form:not([method=get]) [name="legal_name"] | Razão social (obrigatório)
3: main form:not([method=get]) [name="status"] | Situação (obrigatório)
4: main form:not([method=get]) [name="zip_code"] | CEP (obrigatório)
5: main form:not([method=get]) [name="street"] | Logradouro (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar** e **Situação**.

## O que a lista mostra

Colunas: **Nome**, **Cnpj** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Ver** abre os detalhes do registro, sem alterar nada.

> O CNPJ é opcional: dá para cadastrar o condomínio antes de ele ter CNPJ e completar depois.

> Ao digitar o CEP, o endereço se preenche sozinho. Confira o número e o complemento.

> Na tela: *Mostrando só Residencial Jardim das Acácias, o condomínio escolhido na barra superior. Ver todos os condomínios*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Painel do condomínio](artigo:painel-do-condominio), [Painel da unidade](artigo:painel-da-unidade) e [Cadastrar unidades](artigo:unidades).
