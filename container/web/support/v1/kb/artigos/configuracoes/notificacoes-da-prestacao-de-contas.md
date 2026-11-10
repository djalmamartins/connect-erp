---
codigo: AP-151
titulo: Envio da prestação de contas aos condôminos
resumo: Define se a prestação de contas é enviada aos condôminos, em que dia, por qual canal e o que vai junto (balancete, demonstrativo, comprovantes).
categoria: configuracoes
rota: /configuracoes/notificacoes-da-prestacao-de-contas
menu: Configurações > Aplicativo e notificações > Notificações da prestação de contas
icone: options-outline
situacao: exemplo
relacionados: layout-da-aplicacao, notificacoes-aplicativo, banners, alertas
palavras: notificações da prestação de contas, configurações, AP-151
rascunho: nao
---

Define se a prestação de contas é enviada aos condôminos, em que dia, por qual canal e o que vai junto (balancete, demonstrativo, comprovantes).

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Notificações da prestação de contas]].

```tela notificacoes-da-prestacao-de-contas
rota: /configuracoes/notificacoes-da-prestacao-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Notificações da prestação de contas
1: menu | Notificações da prestação de contas no menu lateral
2: main form:not([method=get]) | Envio
```

## Envio

1. Preencha os campos que quer alterar.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela notificacoes-da-prestacao-de-contas-form
rota: /configuracoes/notificacoes-da-prestacao-de-contas
condominio: residencial-jardim-das-acacias
legenda: Envio
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-151-0-dia-do-envio"] | Dia do envio
2: main form:not([method=get]) [name="ap-151-0-canal"] | Canal
3: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Enviar prestação aos condôminos**.
- **Dia do envio**.
- **Canal**: Opções: E-mail, Aplicativo e Ambos.
- **Incluir balancete**.
- **Incluir demonstrativo**.
- **Incluir comprovantes**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Aparência do aplicativo](artigo:layout-da-aplicacao), [Notificações do aplicativo](artigo:notificacoes-aplicativo) e [Banners do aplicativo](artigo:banners).
