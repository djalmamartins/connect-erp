---
codigo: AP-159
titulo: Configurar alertas
resumo: Alertas: alertar unidade em atraso há mais de (dias); alertar contrato e seguro que vencem em (dias); alertar conta bancária com saldo abaixo de.
categoria: configuracoes
rota: /configuracoes/alertas
menu: Configurações > Aplicativo e notificações > Alertas
icone: options-outline
situacao: funciona
palavras: alertas, configurações, AP-159
rascunho: nao
---

## Para que serve

Aqui ficam os parâmetros de **Alertas**. Cada um tem um padrão do sistema; a Connect pode mudar o padrão para todos os condomínios, e cada condomínio pode ter o seu valor quando o parâmetro permite. As mudanças passam pela API `POST /api/v1/settings` e ficam na auditoria.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Alertas]].

```tela alertas
rota: /configuracoes/alertas
condominio: residencial-jardim-das-acacias
legenda: Tela Alertas
1: menu | Alertas no menu lateral
2: main form[data-setting-form] | Um parâmetro, com a origem do valor
3: main form[data-setting-form] button[type=submit] | Botão {{Salvar}}
```

## Os parâmetros

- **Alertar unidade em atraso há mais de (dias)**: número inteiro, de 1 a 365. Padrão do sistema: 30. Cada condomínio pode ter o seu.
- **Alertar contrato e seguro que vencem em (dias)**: número inteiro, de 1 a 365. Padrão do sistema: 30. Cada condomínio pode ter o seu.
- **Alertar conta bancária com saldo abaixo de**: valor em reais. Padrão do sistema: R$ 0,00. Cada condomínio pode ter o seu.

## Como alterar

1. Para mudar o padrão da Connect, deixe "Todos os condomínios" na barra superior. Para mudar só um condomínio, escolha-o na barra.
2. Altere o valor do parâmetro e clique em {{Salvar}} ao lado dele.
3. O selo abaixo do campo mostra de onde vem o valor: **Padrão do sistema**, **Padrão da Connect** ou **Próprio do condomínio**.
4. Para desfazer, clique em {{Voltar ao padrão}}: o condomínio volta a seguir o padrão da Connect, e a Connect volta ao padrão do sistema.

>! O padrão da Connect só muda com acesso a todos os condomínios. Parâmetro que vale para todos não aparece para edição com um condomínio escolhido.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; salvar, **erp.settings.manage**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]].
