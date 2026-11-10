---
codigo: AP-019
titulo: Cadastrar e gerenciar patrimônio
resumo: Bens do condomínio: móveis, equipamentos, eletrônicos e ferramentas.
categoria: condominios
rota: /condominios/patrimonio
menu: Condomínios > Patrimônio
icone: list-outline
situacao: funciona
palavras: patrimônio, condomínios, AP-019
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Condomínios > Patrimônio]].

```tela patrimonio
rota: /condominios/patrimonio
condominio: residencial-jardim-das-acacias
legenda: Tela Patrimônio
1: menu | Patrimônio no menu lateral
2: main :is(a,button):has-text("Novo bem") | Botão {{Novo bem}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Categoria, Situação
4: main table thead | Lista com Descrição, Condomínio, Número de patrimônio, Categoria, Estado, Valor de aquisição e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo bem}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela patrimonio-novo
rota: /condominios/patrimonio/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de bem
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Descrição** (obrigatório).
- **Número de patrimônio**.
- **Categoria** (obrigatório): opções: Mobiliário, Equipamento, Eletrônico, Ferramenta, Segurança, Lazer e Outro.
- **Estado** (obrigatório): opções: Bom, Regular, Em manutenção e Inservível.
- **Data de aquisição**: no formato dia/mês/ano.
- **Valor de aquisição**: em reais, por exemplo 1.250,00.
- **Localização**.
- **Fornecedor**.
- **Nota fiscal nº**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

