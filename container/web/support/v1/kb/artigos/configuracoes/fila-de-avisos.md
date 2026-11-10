---
codigo: CN-005
titulo: Fila de avisos por e-mail
resumo: Os avisos que o ERP manda por e-mail, como a chamada da lista de espera. Cada aviso tenta até 5 vezes; o que falhou pode ser reenviado daqui.
categoria: configuracoes
rota: /configuracoes/fila-de-avisos
menu: Configurações > Aplicativo e notificações > Fila de avisos
icone: list-outline
situacao: funciona
relacionados: integracoes, alertas, banners, notificacoes-aplicativo
palavras: fila de avisos, configurações, CN-005
rascunho: nao
---

Os avisos que o ERP manda por e-mail, como a chamada da lista de espera. Cada aviso tenta até 5 vezes; o que falhou pode ser reenviado daqui.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Fila de avisos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela fila-de-avisos
rota: /configuracoes/fila-de-avisos
condominio: residencial-jardim-das-acacias
legenda: Tela Fila de avisos
1: menu | Fila de avisos no menu lateral
2: main table thead | Colunas da lista
```

## O que a lista mostra

Colunas: **Aviso**, **Situação** e **Atualizado**.

## Ações de cada linha

- **Trilha** mostra cada tentativa de envio do aviso, com data e resultado.

> Na tela: *Modo de entrega: Só registro (nada é enviado) Os avisos ficam só no registro do sistema. Nenhum e-mail sai.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Situação das integrações](artigo:integracoes), [Alertas do sistema](artigo:alertas) e [Banners do aplicativo](artigo:banners).
