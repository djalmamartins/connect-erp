---
codigo: AP-171
titulo: Situação das integrações
resumo: Mostra a situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. No card Banco Inter você também liga uma conta do Inter (ambiente de testes) à cobrança; o resto da configuração fica no servidor.
categoria: configuracoes
rota: /configuracoes/integracoes
menu: Configurações > Aplicativo e notificações > Integrações
icone: list-outline
situacao: funciona
relacionados: fila-de-avisos, alertas, banners, notificacoes-aplicativo
palavras: integrações, configurações, AP-171
rascunho: nao
---

Mostra a situação das integrações da Connect: banco, e-mail, antivírus e índices de correção. No card Banco Inter você também liga uma conta do Inter (ambiente de testes) à cobrança; o resto da configuração fica no servidor.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Integrações]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela integracoes
rota: /configuracoes/integracoes
condominio: residencial-jardim-das-acacias
legenda: Tela Integrações
1: menu | Integrações no menu lateral
2: main table thead | Colunas da lista
```

## O que a lista mostra

Colunas: **Situação** e **Arquivos**.

> Na tela: *Antivírus desligado Os arquivos enviados entram como "não verificados". Quando o antivírus for ligado, a reverificação passa por eles em lotes.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Ligar a cobrança pelo Banco Inter](artigo:banco-inter), [Fila de avisos por e-mail](artigo:fila-de-avisos), [Alertas do sistema](artigo:alertas) e [Banners do aplicativo](artigo:banners).
