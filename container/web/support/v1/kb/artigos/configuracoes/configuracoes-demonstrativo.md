---
codigo: AP-158
titulo: Modelo do demonstrativo
resumo: Demonstrativo: mostrar contas em aberto no demonstrativo; agrupar o demonstrativo por.
categoria: configuracoes
rota: /configuracoes/demonstrativo
menu: Configurações > Financeiro e parâmetros > Demonstrativo
icone: options-outline
situacao: funciona
palavras: demonstrativo, configurações, AP-158
rascunho: nao
manual: sim
---

## Para que serve

Aqui ficam os parâmetros de **Demonstrativo**. Cada um tem um padrão do sistema; a Connect pode mudar o padrão para todos os condomínios, e cada condomínio pode ter o seu valor quando o parâmetro permite. As mudanças passam pela API `POST /api/v1/settings` e ficam na auditoria.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Demonstrativo]].

```tela configuracoes-demonstrativo
rota: /configuracoes/demonstrativo
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo
1: menu | Demonstrativo no menu lateral
2: main form[data-setting-form] | Um parâmetro, com a origem do valor
3: main form[data-setting-form] button[type=submit] | Botão {{Salvar}}
```

## Os parâmetros

- **Mostrar contas em aberto no demonstrativo**: Sim ou Não. Padrão do sistema: Sim. Cada condomínio pode ter o seu.
- **Agrupar o demonstrativo por**: escolha numa lista. Padrão do sistema: Conta do plano. Cada condomínio pode ter o seu.

## Como alterar

1. Para mudar o padrão da Connect, deixe "Todos os condomínios" na barra superior. Para mudar só um condomínio, escolha-o na barra.
2. Altere o valor do parâmetro e clique em {{Salvar}} ao lado dele.
3. O selo abaixo do campo mostra de onde vem o valor: **Padrão do sistema**, **Padrão da Connect** ou **Próprio do condomínio**.
4. Para desfazer, clique em {{Voltar ao padrão}}: o condomínio volta a seguir o padrão da Connect, e a Connect volta ao padrão do sistema.

>! O padrão da Connect só muda com acesso a todos os condomínios. Parâmetro que vale para todos não aparece para edição com um condomínio escolhido.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; salvar, **erp.settings.manage**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]].
