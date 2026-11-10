---
codigo: CN-001
titulo: Vistorias nos condomínios
resumo: Registra as visitas presenciais aos condomínios, com check-in pelo GPS do celular, checklist do que foi verificado, fotos e o relatório final.
categoria: operacional
rota: /operacional/vistorias
menu: Operacional > Rotina > Vistorias
icone: list-outline
situacao: funciona
relacionados: chamados, manutencoes, ocorrencias, reservas-areas-comuns
palavras: vistorias, operacional, CN-001
rascunho: nao
---

Registra as visitas presenciais aos condomínios, com check-in pelo GPS do celular, checklist do que foi verificado, fotos e o relatório final.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Vistorias]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela vistorias
rota: /operacional/vistorias
condominio: residencial-jardim-das-acacias
legenda: Tela Vistorias
1: menu | Vistorias no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova vistoria") | Botão {{Nova vistoria}}
3: main .erp-page-head__actions :is(a,button):has-text("Configurar") | Botão {{Configurar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio e Situação
```

## Outros botões da tela

- {{Configurar}}: define a programação das vistorias e os itens do checklist.
- {{Nova vistoria}}: começa uma vistoria: escolha o condomínio, faça o check-in no local e siga o checklist.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Programação**
- **Vistorias recentes**

> Faça a vistoria pelo celular, no próprio condomínio: o check-in usa a localização para comprovar a visita.

> Na tela: *Em Configurar, escolha os modelos de checklist que cada condomínio segue e a periodicidade.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Abrir e acompanhar chamados](artigo:chamados), [Manutenções preventivas e corretivas](artigo:manutencoes) e [Registrar ocorrências](artigo:ocorrencias).
