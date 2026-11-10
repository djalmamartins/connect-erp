---
codigo: AP-153
titulo: Configurar permissões de Moradores
resumo: Ligar ou desligar cada função do portal do morador no condomínio: boletos, reservas, ocorrências, comunicados e documentos.
categoria: configuracoes
rota: /configuracoes/permissoes-de-moradores
menu: Configurações > Acesso > Permissões de Moradores
icone: options-outline
situacao: funciona
relacionados: criacao-de-usuarios-para-moradores
palavras: permissões de moradores, portal do morador, funções, AP-153
rascunho: nao
---

## Para que serve

Define o que o portal do morador mostra em cada condomínio. **Tudo começa desligado**: a função só aparece no portal depois que alguém liga aqui.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Permissões de Moradores]].

```tela permissoes-de-moradores
rota: /configuracoes/permissoes-de-moradores
condominio: residencial-jardim-das-acacias
legenda: Tela Permissões de Moradores
1: menu | Permissões de Moradores no menu lateral
2: main [data-erp-access] | Funções do portal do morador
```

>! A configuração é de **um condomínio**. Escolha o condomínio na barra superior.

## Como usar

1. Marque a caixa da função para **ligar** ou desmarque para **desligar**. A mudança vale na hora.
2. A coluna **Alterado em** mostra a última mudança.

As funções são: Boletos e segunda via, Reservas de áreas comuns, Ocorrências, Comunicados e Documentos do condomínio.

## Quem pode usar

Ver exige **erp.access_admin.view**; ligar e desligar, **erp.access_admin.manage**. Cada mudança fica na auditoria com o antes e o depois.
