---
codigo: AP-004
titulo: Cadastrar um condomínio
resumo: Com um condomínio na barra superior, o menu Condomínios abre a visão geral dele. Em Todos os condomínios, aparece a lista para selecionar um. O cadastro de um condomínio novo segue um guia: unidades, proprietários, síndico e conselho, áreas e o checklist por último.
categoria: condominios
rota: /condominios
menu: Condomínios > Condomínios
icone: list-outline
situacao: funciona
relacionados: escolher-o-condominio, unidades, proprietarios-e-inquilinos, sindico-e-conselho, areas-vinculaveis, painel-do-condominio
palavras: condomínios, AP-004, prédio, edifício, checklist, cnpj, cep, selecionar, visão geral, guia, receita
manual: sim
rascunho: nao
---

Aqui ficam os condomínios administrados pela Connect que a sua conta pode ver. O que aparece ao abrir o menu depende do condomínio escolhido na barra superior.

## Onde fica

No menu lateral, abra [[Condomínios > Condomínios]].

- **Com um condomínio na barra superior**, a tela abre direto a **visão geral** dele: identificação, endereço e contato, estrutura, contrato e banco, o andamento do cadastro e os atalhos para os cadastros dele.
- **Em Todos os condomínios**, a tela mostra a lista dos condomínios do seu acesso.

```tela condominios
rota: /condominios
legenda: Lista de condomínios (barra superior em Todos)
1: menu | Condomínios no menu lateral
2: main table tbody tr:first-child a:has-text("Selecionar") | Botão {{Selecionar}}
3: main .erp-page-head__actions :is(a,button):has-text("Novo condomínio") | Botão {{Novo condomínio}}
4: main .erp-filters | Filtros: Pesquisar e Situação
```

## Selecionar um condomínio para o sistema todo

Na lista, clique em {{Selecionar}}, na frente do nome do condomínio. Ele passa para a barra superior e **todas as telas** do sistema passam a mostrar só os dados dele: cadastros, financeiro, cobrança, relatórios. A tela abre a visão geral do condomínio escolhido.

Para voltar à lista, escolha **Todos os condomínios** na barra superior ou clique em {{Todos os condomínios}} no alto da visão geral.

>> Mais sobre a barra superior em [Escolher o condomínio](artigo:escolher-o-condominio).

## Visão geral do condomínio

```tela condominios-visao-geral
rota: /condominios
condominio: residencial-jardim-das-acacias
legenda: Visão geral do condomínio
1: main [data-erp-setup-guide] | Andamento do cadastro
2: main #condo-data-title | Dados completos do cadastro
3: main #condo-related-title | Cadastros do condomínio
4: main .erp-page-head__actions :is(a,button):has-text("Editar") | Botão {{Editar}}
```

- O cartão **Cadastro do condomínio** mostra os passos do cadastro e o que já foi feito (por exemplo, "12 de 40 unidades" com proprietário). Clique em {{Continuar o cadastro}} para abrir o guia no primeiro passo que falta.
- **Cadastros do condomínio** abre cada lista já filtrada por ele.
- {{Painel}} mostra ocupação, financeiro e rotina; {{Checklist de chegada}} mostra documentos, vencimentos e prazos.

## Cadastrar um condomínio novo

1. Clique em {{Novo condomínio}}.
2. Comece pelo **CNPJ**, se o condomínio tiver. Ao completar os 14 dígitos, o sistema busca os dados públicos na Receita e preenche nome, razão social, endereço, telefone e e-mail. Só os campos vazios são preenchidos: confira tudo antes de salvar.
3. Complete o que faltar. São obrigatórios **Nome**, **Razão social**, **Situação**, **CEP**, **Logradouro**, **Número**, **Bairro**, **Cidade** e **UF**.
4. Clique em {{Salvar}}.

```tela condominios-novo-condominio
rota: /condominios/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo condomínio
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="document"] | CNPJ (preenche os dados)
2: main form:not([method=get]) [name="name"] | Nome (obrigatório)
3: main form:not([method=get]) [name="legal_name"] | Razão social (obrigatório)
4: main form:not([method=get]) [name="zip_code"] | CEP (preenche o endereço)
5: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

> O CNPJ é opcional: dá para cadastrar o condomínio sem CNPJ e completar depois. Sem CNPJ, o CEP continua preenchendo o endereço.

>! Se a Receita mostrar o CNPJ como baixado, suspenso ou inapto, a tela avisa embaixo do campo. Confira com o síndico antes de seguir.

## O guia depois de salvar

Ao salvar, o condomínio novo vai para a barra superior e um guia aparece no topo das telas, na ordem do cadastro:

1. **Condomínio**: os dados que você acabou de salvar.
2. **Unidades**: pelo [cadastro rápido](artigo:unidades), com blocos, andares e unidades por andar.
3. **Proprietários**: o proprietário de cada unidade, em [Proprietários e inquilinos](artigo:proprietarios-e-inquilinos).
4. **Síndico e conselho**: síndico, subsíndico e conselheiros, com e-mail e telefone (obrigatórios).
5. **Áreas vinculadas**: vagas de garagem, depósitos e boxes ([Áreas vinculáveis](artigo:areas-vinculaveis)). Pode ficar vazio.
6. **Checklist**: por último, para ver o que falta e definir os prazos.

Use {{Próximo}} para ir ao passo seguinte ou clique em qualquer passo para ir direto a ele. Nada é bloqueado: dá para pular um passo e voltar depois. No checklist, {{Concluir cadastro}} fecha o guia e volta à visão geral. {{Fechar o guia}} fecha antes; para retomar, use {{Continuar o cadastro}} na visão geral.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**; cadastrar e editar pedem a permissão de gerenciar condomínios. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou). Os passos do guia só aparecem para quem pode abrir a tela de cada um.

>> Veja também: [Painel do condomínio](artigo:painel-do-condominio), [Cadastrar unidades](artigo:unidades) e [Síndico e conselho](artigo:sindico-e-conselho).
