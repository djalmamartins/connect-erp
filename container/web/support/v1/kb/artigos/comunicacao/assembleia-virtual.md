---
codigo: AP-018
titulo: Assembleia Virtual
resumo: Como usar a tela Assembleia Virtual do Connect ERP: onde fica no menu, o que cada parte mostra e o passo a passo.
categoria: comunicacao
rota: /comunicacao/assembleia-virtual
menu: Comunicação > Canais > Assembleia Virtual
icone: list-outline
situacao: exemplo
palavras: assembleia virtual, comunicação, AP-018
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Comunicação > Canais > Assembleia Virtual]].

```tela assembleia-virtual
rota: /comunicacao/assembleia-virtual
condominio: residencial-jardim-das-acacias
legenda: Tela Assembleia Virtual
1: menu | Assembleia Virtual no menu lateral
2: main :is(a,button):has-text("Nova assembleia") | Botão {{Nova assembleia}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, Pesquisar
5: main table thead | Lista com Data, Condomínio, Tipo, Pauta, Presenças e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como usar

Os botões no alto da tela são {{Exportar}} e {{Nova assembleia}}.

1. Ajuste os filtros (Condomínio e Pesquisar).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Data**
- **Condomínio**
- **Tipo**
- **Pauta**
- **Presenças**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

