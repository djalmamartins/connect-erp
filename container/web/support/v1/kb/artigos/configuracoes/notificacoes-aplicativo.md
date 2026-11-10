---
codigo: AP-155
titulo: Notificações do aplicativo
resumo: Escolha quais avisos o morador recebe no aplicativo: boleto novo, boleto vencendo, comunicado, entrega na portaria, reserva aprovada e assembleia.
categoria: configuracoes
rota: /configuracoes/notificacoes-aplicativo
menu: Configurações > Aplicativo e notificações > Notificações aplicativo
icone: options-outline
situacao: exemplo
relacionados: layout-da-aplicacao, banners, notificacoes-da-prestacao-de-contas, alertas
palavras: notificações aplicativo, configurações, AP-155
rascunho: nao
---

Escolha quais avisos o morador recebe no aplicativo: boleto novo, boleto vencendo, comunicado, entrega na portaria, reserva aprovada e assembleia.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Notificações aplicativo]].

```tela notificacoes-aplicativo
rota: /configuracoes/notificacoes-aplicativo
condominio: residencial-jardim-das-acacias
legenda: Tela Notificações aplicativo
1: menu | Notificações aplicativo no menu lateral
2: main form:not([method=get]) | Notificações
```

## Notificações

1. Preencha os campos que quer alterar.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela notificacoes-aplicativo-form
rota: /configuracoes/notificacoes-aplicativo
condominio: residencial-jardim-das-acacias
legenda: Notificações
recorte: main form:not([method=get])
1: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Novo boleto**.
- **Boleto vencendo**.
- **Comunicado novo**.
- **Entrega na portaria**.
- **Reserva aprovada**.
- **Assembleia convocada**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Aparência do aplicativo](artigo:layout-da-aplicacao), [Banners do aplicativo](artigo:banners) e [Envio da prestação de contas aos condôminos](artigo:notificacoes-da-prestacao-de-contas).
