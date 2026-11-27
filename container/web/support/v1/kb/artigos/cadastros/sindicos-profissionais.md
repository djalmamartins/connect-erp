---
codigo: CN-012
titulo: Cadastrar síndicos profissionais
resumo: Síndicos profissionais (pessoa ou empresa) que a Connect indica aos condomínios. Cadastre uma vez e escolha-os em Síndico e conselho, em qualquer prédio.
categoria: cadastros
rota: /cadastros/sindicos-profissionais
menu: Cadastros > Síndicos profissionais
icone: list-outline
situacao: funciona
relacionados: sindico-e-conselho, proprietarios-e-inquilinos, fornecedores
palavras: síndicos profissionais, síndico profissional, cadastros, CN-012, síndico externo, empresa de sindicatura
rascunho: nao
manual: sim
---

Síndicos profissionais (pessoa ou empresa) que a Connect indica aos condomínios. Cadastre uma vez e escolha-os em Síndico e conselho, em qualquer prédio.

## Onde fica

No menu lateral, abra [[Cadastros > Síndicos profissionais]]. O cadastro é da Connect e vale para todos os condomínios: a lista é a mesma com qualquer condomínio na barra superior.

```tela sindicos-profissionais
rota: /cadastros/sindicos-profissionais
legenda: Tela Síndicos profissionais
1: menu | Síndicos profissionais no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo síndico profissional") | Botão {{Novo síndico profissional}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo síndico profissional}}, no alto da lista.
2. Preencha os campos obrigatórios: **Nome ou razão social**, **E-mail**, **Telefone** e **Situação**.
3. Clique em {{Salvar}}.
4. O síndico aparece na lista e, na hora, no quadro **Escolher a pessoa** de [[Cadastros > Síndico e conselho]] (clique em **Atualizar a lista** se a tela já estava aberta).

```tela sindicos-profissionais-novo
rota: /cadastros/sindicos-profissionais/novo
legenda: Formulário: novo síndico profissional
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="name"] | Nome ou razão social (obrigatório)
2: main form:not([method=get]) [name="document"] | CPF/CNPJ
3: main form:not([method=get]) [name="email"] | E-mail (obrigatório)
4: main form:not([method=get]) [name="phone"] | Telefone (obrigatório)
5: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Nome ou razão social** (obrigatório).
- **CPF/CNPJ**: com o CNPJ completo, o sistema busca os dados na Receita e preenche o nome, o e-mail e o telefone que estiverem vazios. O documento não pode se repetir.
- **Responsável**: quem atende, quando o síndico profissional é uma empresa.
- **E-mail** (obrigatório) e **Telefone** (obrigatório): vão para o cadastro do síndico quando ele é escolhido.
- **Observações**.
- **Situação** (obrigatório): Ativo e Inativo. Só os ativos aparecem para escolher.

## Escolher o síndico profissional de um prédio

Em [[Cadastros > Síndico e conselho]], clique em {{Novo membro}}, escolha o cargo **Síndico** ou **Subsíndico** e, no quadro **Escolher a pessoa**, abra a aba **Síndicos profissionais** (ou digite o nome no campo do nome). O formulário é preenchido com os dados dele.

>> Veja também: [Cadastrar síndico e conselho](artigo:sindico-e-conselho).

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- O CPF ou CNPJ não pode se repetir: o síndico profissional é cadastrado uma vez só e escolhido em vários condomínios.
- Síndico profissional ligado a um membro ativo de Síndico e conselho não pode ser excluído. Inative-o: ele sai das escolhas e o histórico continua.
- Toda inclusão, alteração e exclusão entra no histórico (quem fez, quando e o que mudou).

## Quem pode usar

Usa as mesmas permissões de **Síndico e conselho**: consultar com "Visualizar síndico e conselho" e cadastrar com "Gerenciar síndico e conselho". O administrador libera o acesso em [[Studio > Usuários]].
