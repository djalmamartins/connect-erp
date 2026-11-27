---
codigo: AP-014
titulo: Cadastrar síndico e conselho
resumo: Registre quem é síndico, subsíndico e membro dos conselhos fiscal e consultivo, escolhendo entre os moradores do prédio ou os síndicos profissionais, com mandato de 1 ou 2 anos. O síndico aparece nos documentos e nas assinaturas da prestação de contas.
categoria: cadastros
rota: /cadastros/sindico-e-conselho
menu: Cadastros > Síndico e conselho
icone: list-outline
situacao: funciona
relacionados: sindicos-profissionais, proprietarios-e-inquilinos, funcionarios, fornecedores
palavras: síndico e conselho, cadastros, AP-014, síndico, subsíndico, conselho fiscal, mandato, síndico morador, síndico profissional, conselheiro
rascunho: nao
manual: sim
---

Registre quem é síndico, subsíndico e membro dos conselhos fiscal e consultivo, escolhendo entre os moradores do prédio ou os síndicos profissionais, com mandato de 1 ou 2 anos. O síndico aparece nos documentos e nas assinaturas da prestação de contas.

## Onde fica

No menu lateral, abra [[Cadastros > Síndico e conselho]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela sindico-e-conselho
rota: /cadastros/sindico-e-conselho
condominio: residencial-jardim-das-acacias
legenda: Tela Síndico e conselho
1: menu | Síndico e conselho no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo membro") | Botão {{Novo membro}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Cargo e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo membro}}, no alto da lista.
2. Confira o **Condomínio** e escolha o **Cargo**.
3. Escolha a pessoa de um destes jeitos:
   - **Digite o nome**: a partir de 2 letras, aparecem as sugestões dos moradores do prédio e, para síndico e subsíndico, dos síndicos profissionais. Clique na pessoa (ou use as setas e Enter).
   - **Quadro Escolher a pessoa**, ao lado do formulário, acima do histórico: a aba **Moradores do prédio** lista todos os proprietários, inquilinos e moradores ativos do condomínio, com a unidade; a aba **Síndicos profissionais** lista os cadastrados pela Connect. Use a busca e clique na pessoa.
4. O formulário é preenchido com o nome, o CPF/CNPJ, o e-mail, o telefone e a unidade. Confira e complete o que faltar.
5. Escolha a **Duração do mandato** (1 ou 2 anos) e informe o **Início do mandato**: o **Fim do mandato** é calculado sozinho.
6. Clique em {{Salvar}}.
7. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

> Se a pessoa ainda não é morador cadastrado, digite os dados à mão ou cadastre-a antes em [[Cadastros > Proprietários e inquilinos]].

```tela sindico-e-conselho-escolher-pessoa
rota: /cadastros/sindico-e-conselho/novo
condominio: residencial-jardim-das-acacias
legenda: Quadro Escolher a pessoa, com os moradores do prédio
esperar: 800
1: main [data-erp-board-picker] [data-board-picker-tab="residents"] | Aba Moradores do prédio
2: main [data-erp-board-picker] [data-board-picker-tab="professionals"] | Aba Síndicos profissionais
3: main [data-erp-board-picker] [data-board-picker-search] | Busca por nome, unidade ou CPF/CNPJ
4: main [data-erp-board-picker] [data-board-picker-list] | Clique na pessoa para preencher o formulário
```

## Síndico morador ou síndico profissional

- **Síndico morador**: escolha na aba **Moradores do prédio** (ou nas sugestões do nome). O membro fica ligado ao morador, aparece em **Morador do prédio** e leva a unidade dele.
- **Síndico profissional**: escolha na aba **Síndicos profissionais**. Se ele ainda não está na lista, clique em {{Cadastrar síndico profissional}} (abre em outra aba), salve o cadastro e volte em **Atualizar a lista**. O síndico profissional vale para todos os condomínios: cadastre uma vez só.
- **Desvincular** tira a ligação com o morador ou com o síndico profissional, sem apagar o que já foi preenchido.

>> Veja também: [Cadastrar síndicos profissionais](artigo:sindicos-profissionais).

## Conselho fiscal e consultivo

Para **Conselheiro fiscal**, **Conselheiro consultivo** e **Suplente**, o quadro mostra só os **moradores do prédio**: o conselho é formado por condôminos. A aba de síndicos profissionais some e, se havia um escolhido, ele é desvinculado.

## Duração do mandato

O mandato é de **1 ano** ou **2 anos** (o máximo é 2). Ao escolher a duração e o início, o fim é calculado como o dia anterior ao aniversário do início: início em 15/03/2026 com 2 anos termina em 14/03/2028. Dá para ajustar o fim para antes (por exemplo, para casar com a assembleia), mas não para depois do limite da duração escolhida.

```tela sindico-e-conselho-novo-membro
rota: /cadastros/sindico-e-conselho/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo membro
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="role"] | Cargo (obrigatório)
3: main form:not([method=get]) [name="name"] | Nome ou razão social (obrigatório)
4: main form:not([method=get]) [name="term_years"] | Duração do mandato: 1 ou 2 anos
5: main form:not([method=get]) [name="term_starts_on"] | Início do mandato (obrigatório)
6: main form:not([method=get]) [name="term_ends_on"] | Fim do mandato, calculado sozinho
7: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Cargo** (obrigatório): Opções: Síndico, Subsíndico, Conselheiro fiscal, Conselheiro consultivo e Suplente.
- **Nome ou razão social** (obrigatório).
- **CPF/CNPJ**: CNPJ para síndico profissional.
- **Unidade**: Unidade cadastrada em Condomínios › Unidades. Vem preenchida ao escolher um morador.
- **Unidade (texto)**: Vazio para síndico profissional.
- **Morador do prédio**: quem foi escolhido entre os moradores (ou Nenhum).
- **Síndico profissional**: quem foi escolhido entre os síndicos profissionais; só para síndico e subsíndico.
- **Duração do mandato** (obrigatório): 1 ano ou 2 anos.
- **Início do mandato** (obrigatório).
- **Fim do mandato** (obrigatório): calculado pela duração; dá para ajustar para antes.
- **E-mail** (obrigatório): Exemplo: nome@exemplo.com.
- **Telefone** (obrigatório).

> E-mail e telefone são obrigatórios porque são o canal de comunicação com o síndico e o conselho: avisos, assembleias e aprovações.
- **Observações**: Ex.: ata da assembleia que elegeu.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Cargo** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome ou razão social**, **Condomínio**, **Cargo**, **Fim do mandato**, **Telefone** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Ao trocar de síndico, encerre o mandato anterior com a data de fim em vez de excluir, para manter o histórico.

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- Cada condomínio só pode ter um síndico ativo. Para cadastrar o novo, inative o atual antes.
- E-mail e telefone são obrigatórios, e o fim do mandato precisa ser depois do início.
- O fim do mandato não inativa o membro sozinho: depois da eleição, inative quem saiu.
- A pessoa vem dos moradores do prédio ou dos síndicos profissionais (sugestões ao digitar o nome e quadro Escolher a pessoa); o conselho é só de moradores.
- O mandato é de 1 ou 2 anos: o fim é calculado pelo início e não pode passar do limite da duração.
- A lista de moradores só aparece para quem também pode ver Proprietários e inquilinos. Ao salvar, o servidor confere o vínculo: morador ativo do mesmo prédio, síndico profissional só como síndico ou subsíndico, nunca os dois juntos.
- Antes de gravar, o sistema confere os campos obrigatórios e se o condomínio escolhido está no seu acesso. Se algo estiver errado, nada é gravado e o formulário mostra o que corrigir.
- Toda inclusão, alteração e exclusão entra no histórico (quem fez, quando e o que mudou), que você vê no próprio registro.
- Excluir não apaga de verdade: o registro sai das listas, mas continua guardado com o histórico. Por isso, para quem saiu ou deixou de valer, prefira mudar a **Situação** para inativo.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cadastros**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Cadastrar síndicos profissionais](artigo:sindicos-profissionais), [Cadastrar proprietários e inquilinos](artigo:proprietarios-e-inquilinos) e [Cadastrar funcionários do condomínio](artigo:funcionarios).
