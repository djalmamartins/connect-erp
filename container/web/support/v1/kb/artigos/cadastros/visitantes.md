---
codigo: AP-012
titulo: Cadastrar visitantes autorizados
resumo: Visitantes frequentes e prestadores autorizados por unidade, como diarista, babá ou familiar. A portaria consulta a lista para liberar a entrada.
categoria: cadastros
rota: /cadastros/visitantes
menu: Cadastros > Visitantes
icone: list-outline
situacao: funciona
relacionados: previsao-de-visita, registro-de-acessos, veiculos, animais-de-estimacao
palavras: visitantes, cadastros, AP-012
rascunho: nao
---

Visitantes frequentes e prestadores autorizados por unidade, como diarista, babá ou familiar. A portaria consulta a lista para liberar a entrada.

## Onde fica

No menu lateral, abra [[Cadastros > Visitantes]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela visitantes
rota: /cadastros/visitantes
condominio: residencial-jardim-das-acacias
legenda: Tela Visitantes
1: menu | Visitantes no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo visitante") | Botão {{Novo visitante}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo visitante}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Nome** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela visitantes-novo-visitante
rota: /cadastros/visitantes/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo visitante
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="unit_id"] | Unidade visitada
3: main form:not([method=get]) [name="unit_label"] | Unidade visitada (texto)
4: main form:not([method=get]) [name="name"] | Nome (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade visitada**: Unidade cadastrada em Condomínios › Unidades.
- **Unidade visitada (texto)**: Preenchido pela unidade escolhida; digite só se ela ainda não estiver cadastrada.
- **Nome** (obrigatório).
- **Documento (CPF ou RG)**: Exemplo: CPF ou RG.
- **Telefone**.
- **Acesso permanente**.
- **Autorizado até**: Vazio quando o acesso é permanente.
- **Observações**.
- **Situação** (obrigatório): Opções: Liberado e Bloqueado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **Condomínio**, **Unidade visitada (texto)**, **Documento (cpf ou rg)**, **Autorizado até** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Visitas previstas](artigo:previsao-de-visita), [Registro de acessos da portaria](artigo:registro-de-acessos) e [Cadastrar veículos das unidades](artigo:veiculos).
