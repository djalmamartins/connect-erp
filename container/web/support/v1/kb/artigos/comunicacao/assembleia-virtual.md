---
codigo: AP-018
titulo: Assembleia virtual
resumo: Assembleias do condomínio, com data, tipo, pauta, presenças e situação.
categoria: comunicacao
rota: /comunicacao/assembleia-virtual
menu: Comunicação > Canais > Assembleia Virtual
icone: list-outline
situacao: exemplo
relacionados: comunicados-gerais, votacao, cobranca, agendada
palavras: assembleia virtual, comunicação, AP-018
rascunho: nao
---

Assembleias do condomínio, com data, tipo, pauta, presenças e situação.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Comunicação > Canais > Assembleia Virtual]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela assembleia-virtual
rota: /comunicacao/assembleia-virtual
condominio: residencial-jardim-das-acacias
legenda: Tela Assembleia Virtual
1: menu | Assembleia Virtual no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova assembleia") | Botão {{Nova assembleia}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio e Pesquisar
5: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova assembleia}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Tipo**, **Data** e **Pauta**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.

```tela assembleia-virtual-nova-assembleia
rota: /comunicacao/assembleia-virtual
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova assembleia
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Nova assembleia")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="ap-018-condominio"] | Condomínio (obrigatório)
2: dialog[open] [name="ap-018-tipo"] | Tipo (obrigatório)
3: dialog[open] [name="ap-018-data"] | Data (obrigatório)
4: dialog[open] [name="ap-018-pauta"] | Pauta (obrigatório)
5: dialog[open] [name="ap-018-edital-de-convocacao"] | Edital de convocação
6: dialog[open] button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Tipo** (obrigatório): Opções: AGO e AGE.
- **Data** (obrigatório).
- **Pauta** (obrigatório).
- **Edital de convocação**: PDF, JPG ou PNG. O envio será habilitado com o armazenamento do módulo. Escolha o arquivo no seu computador.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Condomínio** e **Pesquisar**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Data**, **Condomínio**, **Tipo**, **Pauta**, **Presenças** e **Situação**.

## Ações de cada linha

- **Ver** abre os detalhes do registro, sem alterar nada.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Enviar um comunicado por e-mail](artigo:comunicados-gerais), [Votações](artigo:votacao) e [Enviar aviso de cobrança](artigo:cobranca).
