---
codigo: AP-166
titulo: Configurar parâmetros
resumo: Parâmetros: tolerância da soma das frações ideais; máximo de linhas por relatório.
categoria: configuracoes
rota: /configuracoes/parametros
menu: Configurações > Financeiro e parâmetros > Parâmetros
icone: options-outline
situacao: funciona
palavras: parâmetros, configurações, AP-166
rascunho: nao
---

## Para que serve

Aqui ficam os parâmetros de **Parâmetros**. Cada um tem um padrão do sistema; a Connect pode mudar o padrão para todos os condomínios, e cada condomínio pode ter o seu valor quando o parâmetro permite. As mudanças passam pela API `POST /api/v1/settings` e ficam na auditoria.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Parâmetros]].

```tela parametros
rota: /configuracoes/parametros
condominio: residencial-jardim-das-acacias
legenda: Tela Parâmetros
1: menu | Parâmetros no menu lateral
2: main form[data-setting-form] | Um parâmetro, com a origem do valor
3: main form[data-setting-form] button[type=submit] | Botão {{Salvar}}
```

## Os parâmetros

- **Tolerância da soma das frações ideais**: número decimal, de 0 a 0.01. Padrão do sistema: 0.0001. Vale para todos os condomínios.
- **Máximo de linhas por relatório**: número inteiro, de 100 a 20000. Padrão do sistema: 5000. Vale para todos os condomínios.

## Como alterar

1. Para mudar o padrão da Connect, deixe "Todos os condomínios" na barra superior. Para mudar só um condomínio, escolha-o na barra.
2. Altere o valor do parâmetro e clique em {{Salvar}} ao lado dele.
3. O selo abaixo do campo mostra de onde vem o valor: **Padrão do sistema**, **Padrão da Connect** ou **Próprio do condomínio**.
4. Para desfazer, clique em {{Voltar ao padrão}}: o condomínio volta a seguir o padrão da Connect, e a Connect volta ao padrão do sistema.

>! O padrão da Connect só muda com acesso a todos os condomínios. Parâmetro que vale para todos não aparece para edição com um condomínio escolhido.

## Quem pode usar

Ver exige a permissão **erp.settings.view**; salvar, **erp.settings.manage**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]].
