---
codigo: AP-148
titulo: Usuários de imobiliárias
resumo: Cadastrar imobiliárias parceiras, vincular os usuários delas e conceder ou revogar a consulta a cada condomínio.
categoria: configuracoes
rota: /configuracoes/usuarios-de-imobiliarias
menu: Configurações > Acesso > Usuários de imobiliárias
icone: business-outline
situacao: funciona
relacionados: criacao-de-usuarios-para-moradores, permissoes-de-moradores
palavras: imobiliária, parceiro, usuários de imobiliárias, acesso, concessão, AP-148
rascunho: nao
---

## Para que serve

A imobiliária parceira consulta as **unidades e os moradores** dos condomínios que a Connect conceder, com e-mail, telefone e documento mascarados. Ela não vê financeiro, inadimplência nem documentos.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Usuários de imobiliárias]].

```tela usuarios-de-imobiliarias
rota: /configuracoes/usuarios-de-imobiliarias
condominio: residencial-jardim-das-acacias
legenda: Tela Usuários de imobiliárias
1: menu | Usuários de imobiliárias no menu lateral
2: main [data-erp-access] | Nova imobiliária e as imobiliárias cadastradas
```

## Como dar acesso a uma imobiliária

1. Em **Nova imobiliária**, preencha o nome e o CNPJ (e-mail e telefone são opcionais) e clique em {{Cadastrar imobiliária}}.
2. Crie a conta de cada pessoa da imobiliária em [[Studio > Usuários]], como usuário comum e **sem papel**.
3. No cartão da imobiliária, escolha a pessoa em **Vincular usuário** e clique em {{Vincular}}. A lista só traz usuários comuns sem papel, sem acesso a condomínio e sem vínculo.
4. Na linha do usuário, escolha o condomínio e clique em {{Conceder}}. Só aparecem condomínios do seu escopo.

Para tirar um condomínio, clique no **×** ao lado dele. {{Desvincular}} tira o papel de parceiro e todo o acesso da pessoa. {{Desativar imobiliária}} faz o mesmo com todos os usuários dela.

>! Por segurança, uma conta que já tem papel, acesso a condomínio ou permissão própria não pode virar parceiro: use uma conta nova.

## Quem pode usar

Ver exige a permissão **erp.access_admin.view**; cadastrar, vincular e conceder, **erp.access_admin.manage**. Toda mudança fica na auditoria.
