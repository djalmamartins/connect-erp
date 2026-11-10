---
codigo: AP-152
titulo: Criação de usuários para moradores
resumo: Convidar moradores por e-mail para criarem a própria conta, acompanhar os convites e revogar o que ainda está pendente.
categoria: configuracoes
rota: /configuracoes/criacao-de-usuarios-para-moradores
menu: Configurações > Acesso > Criação de usuários para moradores
icone: create-outline
situacao: funciona
relacionados: permissoes-de-moradores, usuarios-de-imobiliarias
palavras: convite, morador, criar usuário, conta do morador, portal, AP-152
rascunho: nao
---

## Para que serve

O morador não recebe senha da administradora: ele recebe um **convite por e-mail** e cria a própria conta. O convite vale 7 dias e só pode ser usado uma vez.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Criação de usuários para moradores]].

```tela criacao-de-usuarios-para-moradores
rota: /configuracoes/criacao-de-usuarios-para-moradores
condominio: residencial-jardim-das-acacias
legenda: Tela Criação de usuários para moradores
1: menu | Criação de usuários para moradores no menu lateral
2: main [data-erp-access] | Convidar moradores e a lista de convites
```

>! Os convites são de **um condomínio**. Escolha o condomínio na barra superior.

## Como convidar

1. Em **Convidar moradores**, marque os moradores. Quem não tem e-mail no cadastro aparece desmarcado e não pode ser convidado.
2. Clique em {{Enviar convites}}. O convite sai pela fila de avisos; fora de produção, os e-mails vão só para o log ou para a caixa de teste.
3. Acompanhe a situação em **Convites**: aguardando aceite, aceito, revogado ou vencido.

Um convite novo para o mesmo morador substitui o pendente. Para cancelar, clique em {{Revogar}}. O morador precisa ter CPF ou CNPJ válido no cadastro, e o condomínio pode enviar até 200 convites por dia.

## Quem pode usar

Exige **erp.access_admin.view** e **erp.residents.view**; convidar e revogar, também **erp.access_admin.manage**. O e-mail aparece mascarado e o convite fica na auditoria, sem o e-mail.
