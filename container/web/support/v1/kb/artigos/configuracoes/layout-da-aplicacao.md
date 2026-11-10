---
codigo: AP-154
titulo: Aparência do aplicativo
resumo: Layout da aplicação: nome exibido no aplicativo.
categoria: configuracoes
rota: /configuracoes/layout-da-aplicacao
menu: Configurações > Aplicativo e notificações > Layout da Aplicação
icone: options-outline
situacao: funciona
palavras: layout da aplicação, configurações, AP-154
rascunho: nao
manual: sim
---

## Para que serve

Aqui ficam os parâmetros de **Layout da aplicação**. Cada um tem um padrão do sistema; a Connect pode mudar o padrão para todos os condomínios, e cada condomínio pode ter o seu valor quando o parâmetro permite. As mudanças passam pela API `POST /api/v1/settings` e ficam na auditoria.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Layout da Aplicação]].

```tela layout-da-aplicacao
rota: /configuracoes/layout-da-aplicacao
condominio: residencial-jardim-das-acacias
legenda: Tela Layout da Aplicação
1: menu | Layout da Aplicação no menu lateral
2: main form[data-setting-form] | Um parâmetro, com a origem do valor
```

## Os parâmetros

- **Nome exibido no aplicativo**: texto, de 2 a 80 caracteres. Padrão do sistema: Connect. Vale para todos os condomínios.

## Como alterar

1. Para mudar o padrão da Connect, deixe "Todos os condomínios" na barra superior. Para mudar só um condomínio, escolha-o na barra.
2. Altere o valor do parâmetro e clique em {{Salvar}} ao lado dele.
3. O selo abaixo do campo mostra de onde vem o valor: **Padrão do sistema**, **Padrão da Connect** ou **Próprio do condomínio**.
4. Para desfazer, clique em {{Voltar ao padrão}}: o condomínio volta a seguir o padrão da Connect, e a Connect volta ao padrão do sistema.

>! O padrão da Connect só muda com acesso a todos os condomínios. Parâmetro que vale para todos não aparece para edição com um condomínio escolhido.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; salvar, **erp.settings.manage**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]].
