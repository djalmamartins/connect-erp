---
codigo: AP-159
titulo: Alertas do sistema
resumo: Define quando o ERP alerta a equipe: conta a pagar vencendo, inadimplência acima de um limite, contrato e seguro a vencer.
categoria: configuracoes
rota: /configuracoes/alertas
menu: Configurações > Aplicativo e notificações > Alertas
icone: options-outline
situacao: exemplo
relacionados: banners, notificacoes-aplicativo, layout-da-aplicacao, notificacoes-da-prestacao-de-contas
palavras: alertas, configurações, AP-159
rascunho: nao
---

Define quando o ERP alerta a equipe: conta a pagar vencendo, inadimplência acima de um limite, contrato e seguro a vencer.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Alertas]].

```tela alertas
rota: /configuracoes/alertas
condominio: residencial-jardim-das-acacias
legenda: Tela Alertas
1: menu | Alertas no menu lateral
2: main form:not([method=get]) | Alertas da equipe
```

## Alertas da equipe

1. Preencha os campos que quer alterar.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela alertas-form
rota: /configuracoes/alertas
condominio: residencial-jardim-das-acacias
legenda: Alertas da equipe
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-159-0-conta-a-pagar-vencendo-dias"] | Conta a pagar vencendo (dias)
2: main form:not([method=get]) [name="ap-159-0-inadimplencia-acima-de"] | Inadimplência acima de (%)
3: main form:not([method=get]) [name="ap-159-0-contrato-a-vencer-dias"] | Contrato a vencer (dias)
4: main form:not([method=get]) [name="ap-159-0-seguro-a-vencer-dias"] | Seguro a vencer (dias)
5: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Conta a pagar vencendo (dias)**.
- **Inadimplência acima de (%)**.
- **Contrato a vencer (dias)**.
- **Seguro a vencer (dias)**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Banners do aplicativo](artigo:banners), [Notificações do aplicativo](artigo:notificacoes-aplicativo) e [Aparência do aplicativo](artigo:layout-da-aplicacao).
