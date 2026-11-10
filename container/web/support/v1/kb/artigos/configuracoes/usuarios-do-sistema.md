---
codigo: AP-149
titulo: Usuários do sistema
resumo: Quem da equipe entra no ERP e em quais condomínios; dar ou tirar o acesso a cada condomínio.
categoria: configuracoes
rota: /configuracoes/usuarios-do-sistema
menu: Configurações > Acesso > Usuários do Sistema
icone: list-outline
situacao: funciona
relacionados: permissoes-de-usuarios, administrativo, usuarios-de-imobiliarias
palavras: usuários do sistema, acesso, condomínios, equipe, AP-149
rascunho: nao
---

## Para que serve

Mostra as contas com acesso aos condomínios do seu escopo, com o papel de cada uma. Aqui você dá ou tira o acesso a condomínios. A conta, a senha, o papel e o 2FA continuam em [[Studio > Usuários]].

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Usuários do Sistema]]. Com um condomínio na barra superior, a lista mostra só quem acessa aquele condomínio.

```tela usuarios-do-sistema
rota: /configuracoes/usuarios-do-sistema
condominio: residencial-jardim-das-acacias
legenda: Tela Usuários do Sistema
1: menu | Usuários do Sistema no menu lateral
2: main [data-erp-access] | Dar acesso e a lista de usuários
```

## Como dar acesso a uma pessoa

1. Crie a conta em [[Studio > Usuários]], com um papel que entre no ERP.
2. Em **Dar acesso a uma conta**, digite o e-mail da conta e marque os condomínios.
3. Clique em {{Dar acesso}}.

Para tirar um condomínio, clique no **×** ao lado dele. {{Tirar todo o acesso}} tira todos os condomínios do seu escopo; a conta continua existindo no Studio.

>! Você só mexe em quem tem papel abaixo do seu. Papéis protegidos e a sua própria conta aparecem só para consulta.
