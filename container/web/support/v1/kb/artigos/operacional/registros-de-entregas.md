---
codigo: AP-136
titulo: Registros de entregas
resumo: Histórico das entregas registradas pela portaria.
categoria: operacional
rota: /operacional/registros-de-entregas
menu: Operacional > Portaria > Registros de Entregas
icone: list-outline
situacao: exemplo
relacionados: entregas, achados-e-perdidos, registro-de-acessos, previsao-de-visita
palavras: registros de entregas, operacional, AP-136
rascunho: nao
---

Histórico das entregas registradas pela portaria.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Registros de Entregas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela registros-de-entregas
rota: /operacional/registros-de-entregas
condominio: residencial-jardim-das-acacias
legenda: Tela Registros de Entregas
1: menu | Registros de Entregas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
3: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De e Até
4: main table thead | Colunas da lista
```

## Pesquisar e filtrar

Use os filtros no alto da lista: **Condomínio**, **De** e **Até**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Retirada em**, **Unidade**, **Retirado por** e **Portaria**.

## Ações de cada linha

- **Ver** abre os detalhes do registro, sem alterar nada.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Entregas na portaria](artigo:entregas), [Achados e perdidos](artigo:achados-e-perdidos) e [Registro de acessos da portaria](artigo:registro-de-acessos).
