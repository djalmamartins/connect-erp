---
codigo: AP-147
titulo: Dados da administradora
resumo: Os dados da Connect que aparecem nos documentos e no atendimento: razão social, CNPJ, e-mail, telefone, horário de atendimento e prazo de resposta.
categoria: configuracoes
rota: /configuracoes/administrativo
menu: Configurações > Acesso > Administrativo
icone: options-outline
situacao: exemplo
relacionados: usuarios-do-sistema, permissoes-de-usuarios, permissoes-de-moradores, usuarios-de-imobiliarias
palavras: administrativo, configurações, AP-147
rascunho: nao
---

Os dados da Connect que aparecem nos documentos e no atendimento: razão social, CNPJ, e-mail, telefone, horário de atendimento e prazo de resposta.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Administrativo]].

```tela administrativo
rota: /configuracoes/administrativo
condominio: residencial-jardim-das-acacias
legenda: Tela Administrativo
1: menu | Administrativo no menu lateral
2: main form:not([method=get]) | Administradora
```

## Administradora

1. Preencha **Razão social** e **CNPJ**.
2. Clique em {{Salvar configurações}}.
3. Se mudou de ideia antes de gravar, {{Desfazer alterações}} volta como estava.

```tela administrativo-form
rota: /configuracoes/administrativo
condominio: residencial-jardim-das-acacias
legenda: Administradora
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="ap-147-0-razao-social"] | Razão social (obrigatório)
2: main form:not([method=get]) [name="ap-147-0-cnpj"] | CNPJ (obrigatório)
3: main form:not([method=get]) [name="ap-147-0-e-mail-de-contato"] | E-mail de contato
4: main form:not([method=get]) [name="ap-147-0-telefone"] | Telefone
5: main form:not([method=get]) [name="ap-147-1-horario-de-atendimento"] | Horário de atendimento
6: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

### O que preencher

- **Razão social** (obrigatório).
- **CNPJ** (obrigatório): Exemplo: Somente números.
- **E-mail de contato**: Exemplo: nome@exemplo.com.
- **Telefone**.
- **Horário de atendimento**.
- **Prazo de resposta (horas)**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Usuários do sistema](artigo:usuarios-do-sistema), [Permissões dos perfis de usuário](artigo:permissoes-de-usuarios) e [Permissões dos moradores no aplicativo](artigo:permissoes-de-moradores).
