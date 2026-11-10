---
codigo: AP-154
titulo: Aparência do aplicativo
resumo: Nome exibido, cor principal e logotipo do aplicativo dos moradores.
categoria: configuracoes
rota: /configuracoes/layout-da-aplicacao
menu: Configurações > Aplicativo e notificações > Layout da Aplicação
icone: options-outline
situacao: exemplo
relacionados: notificacoes-da-prestacao-de-contas, notificacoes-aplicativo, banners, alertas
palavras: layout da aplicação, configurações, AP-154
rascunho: nao
---

Nome exibido, cor principal e logotipo do aplicativo dos moradores.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Layout da Aplicação]].

```tela layout-da-aplicacao
rota: /configuracoes/layout-da-aplicacao
condominio: residencial-jardim-das-acacias
legenda: Tela Layout da Aplicação
1: menu | Layout da Aplicação no menu lateral
2: main form:not([method=get]) | Aplicativo
```

## Aplicativo

1. Preencha **Nome exibido**.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela layout-da-aplicacao-form
rota: /configuracoes/layout-da-aplicacao
condominio: residencial-jardim-das-acacias
legenda: Aplicativo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-154-0-nome-exibido"] | Nome exibido (obrigatório)
2: main form:not([method=get]) [name="ap-154-0-cor-principal"] | Cor principal
3: main form:not([method=get]) [name="ap-154-0-logotipo"] | Logotipo
4: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Nome exibido** (obrigatório).
- **Cor principal**: Opções: Dourado Connect e Azul-marinho.
- **Logotipo**: PDF, JPG ou PNG. O envio será habilitado com o armazenamento do módulo. Escolha o arquivo no seu computador.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Envio da prestação de contas aos condôminos](artigo:notificacoes-da-prestacao-de-contas), [Notificações do aplicativo](artigo:notificacoes-aplicativo) e [Banners do aplicativo](artigo:banners).
