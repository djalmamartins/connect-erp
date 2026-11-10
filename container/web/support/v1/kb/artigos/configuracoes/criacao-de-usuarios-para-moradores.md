---
codigo: AP-152
titulo: Criar o acesso de um morador
resumo: Cria o usuário do morador no aplicativo, ligado à unidade, e envia o convite por e-mail. Revise o resumo antes de confirmar.
categoria: configuracoes
rota: /configuracoes/criacao-de-usuarios-para-moradores
menu: Configurações > Acesso > Criação de usuários para moradores
icone: create-outline
situacao: exemplo
relacionados: usuarios-de-imobiliarias, permissoes-de-moradores, permissoes-de-usuarios, usuarios-do-sistema
palavras: criação de usuários para moradores, configurações, AP-152
rascunho: nao
---

Cria o usuário do morador no aplicativo, ligado à unidade, e envia o convite por e-mail. Revise o resumo antes de confirmar.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Criação de usuários para moradores]].

```tela criacao-de-usuarios-para-moradores
rota: /configuracoes/criacao-de-usuarios-para-moradores
condominio: residencial-jardim-das-acacias
legenda: Tela Criação de usuários para moradores
1: menu | Criação de usuários para moradores no menu lateral
2: main form:not([method=get]) | Dados do lançamento
```

## Dados do lançamento

Campos com * são obrigatórios

1. Preencha **Condomínio**, **Unidade**, **Morador**, **E-mail** e **Perfil no aplicativo**.
2. Clique em {{Confirmar usuário de morador}}.
3. Se mudou de ideia antes de gravar, {{Limpar}} volta como estava.

```tela criacao-de-usuarios-para-moradores-form
rota: /configuracoes/criacao-de-usuarios-para-moradores
condominio: residencial-jardim-das-acacias
legenda: Dados do lançamento
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-152-condominio"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="ap-152-unidade"] | Unidade (obrigatório)
3: main form:not([method=get]) [name="ap-152-morador"] | Morador (obrigatório)
4: main form:not([method=get]) [name="ap-152-e-mail"] | E-mail (obrigatório)
5: main form:not([method=get]) [name="ap-152-perfil-no-aplicativo"] | Perfil no aplicativo (obrigatório)
6: main form:not([method=get]) button:has-text("Confirmar usuário de morador") | Botão {{Confirmar usuário de morador}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Unidade** (obrigatório).
- **Morador** (obrigatório).
- **E-mail** (obrigatório): Exemplo: nome@exemplo.com.
- **Perfil no aplicativo** (obrigatório): Opções: Morador, Proprietário e Responsável.
- **Enviar convite por e-mail**.

> Na tela: *Lançamentos financeiros só são confirmados pelo servidor, com auditoria. Esta tela não simula o resultado.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Usuários de imobiliárias](artigo:usuarios-de-imobiliarias), [Permissões dos moradores no aplicativo](artigo:permissoes-de-moradores) e [Permissões dos perfis de usuário](artigo:permissoes-de-usuarios).
