---
codigo: AP-149
titulo: Usuários do sistema
resumo: As pessoas da equipe que entram no ERP, com perfil, situação, 2FA e último acesso.
categoria: configuracoes
rota: /configuracoes/usuarios-do-sistema
menu: Configurações > Acesso > Usuários do Sistema
icone: list-outline
situacao: exemplo
relacionados: administrativo, permissoes-de-usuarios, permissoes-de-moradores, usuarios-de-imobiliarias
palavras: usuários do sistema, configurações, AP-149
rascunho: nao
---

As pessoas da equipe que entram no ERP, com perfil, situação, 2FA e último acesso.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Usuários do Sistema]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela usuarios-do-sistema
rota: /configuracoes/usuarios-do-sistema
condominio: residencial-jardim-das-acacias
legenda: Tela Usuários do Sistema
1: menu | Usuários do Sistema no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo usuário") | Botão {{Novo usuário}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar e Situação
5: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo usuário}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Nome**, **E-mail**, **Perfil** e **Condomínios com acesso**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.

```tela usuarios-do-sistema-novo-usuario
rota: /configuracoes/usuarios-do-sistema
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo usuário
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Novo usuário")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="ap-149-nome"] | Nome (obrigatório)
2: dialog[open] [name="ap-149-e-mail"] | E-mail (obrigatório)
3: dialog[open] [name="ap-149-perfil"] | Perfil (obrigatório)
4: dialog[open] [name="ap-149-condominios-com-acesso"] | Condomínios com acesso (obrigatório)
5: dialog[open] button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Nome** (obrigatório).
- **E-mail** (obrigatório): Exemplo: nome@exemplo.com.
- **Perfil** (obrigatório): Opções: Administrador, Financeiro, Atendimento e Consulta.
- **Condomínios com acesso** (obrigatório).

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar** e **Situação**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **E-mail**, **Perfil**, **2FA**, **Último acesso** e **Situação**.

## Ações de cada linha

- **Ver** abre os detalhes do registro, sem alterar nada.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Dados da administradora](artigo:administrativo), [Permissões dos perfis de usuário](artigo:permissoes-de-usuarios) e [Permissões dos moradores no aplicativo](artigo:permissoes-de-moradores).
