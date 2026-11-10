---
codigo: AP-136
titulo: Registros de Entregas
resumo: Como usar a tela Registros de Entregas do Connect ERP: onde fica no menu, o que cada parte mostra e o passo a passo.
categoria: operacional
rota: /operacional/registros-de-entregas
menu: Operacional > Portaria > Registros de Entregas
icone: list-outline
situacao: exemplo
palavras: registros de entregas, operacional, AP-136
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Registros de Entregas]].

```tela registros-de-entregas
rota: /operacional/registros-de-entregas
condominio: residencial-jardim-das-acacias
legenda: Tela Registros de Entregas
1: menu | Registros de Entregas no menu lateral
2: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
3: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até
4: main table thead | Lista com Retirada em, Unidade, Retirado por e Portaria
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como usar

Os botões no alto da tela são {{Exportar}}.

1. Ajuste os filtros (Condomínio, De e Até).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Retirada em**
- **Unidade**
- **Retirado por**
- **Portaria**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

