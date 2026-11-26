---
codigo: CN-011
titulo: Propostas: criar, gerar e enviar (ERP)
resumo: Crie a proposta, gere o PDF no modelo da Connect e envie por e-mail, tudo no ERP. Os pedidos do site chegam aqui com aviso no sino.
categoria: comunicacao
rota: /comunicacao/propostas
menu: Comunicação > Comercial > Propostas
icone: document-text-outline
situacao: funciona
relacionados: propostas-do-site, comunicados-gerais
palavras: proposta, pedido de proposta, orçamento, site, comercial, funil, alerta, aviso, sino, novo cliente, PDF, enviar proposta, modelo, nova proposta, CN-011
rascunho: nao
---

Crie a proposta, gere o PDF no modelo da Connect e envie por e-mail, tudo no ERP. Os pedidos do site chegam aqui com aviso no sino.

## Onde fica

No menu lateral, abra [[Comunicação > Comercial > Propostas]]. Só aparece para quem tem a permissão de **gerenciar propostas**.

Desde 26/11/2026, criar, gerar e enviar propostas é feito **só no ERP**. O Studio (Gestão > Propostas) ficou apenas para consulta.

## Como saber que chegou uma proposta do site

Quando alguém preenche **Solicite uma proposta** no site:

1. o **sino** ganha um aviso com o nome do condomínio e o número de unidades. Clique no aviso para abrir o pedido aqui no ERP;
2. aparece um **número** ao lado de **Propostas** no menu (e ao lado de **Comunicação**, com o grupo fechado);
3. no alto da tela aparece o aviso **propostas novas aguardando contato**.

O número e o aviso ficam enquanto houver pedidos com a situação **Nova**. Eles somem sozinhos quando você muda a situação, por exemplo para **Contatada**.

## Cadastrar uma proposta pedida por telefone ou WhatsApp

1. Clique em {{Nova proposta}}.
2. Preencha nome, perfil, e-mail, telefone, condomínio, unidades e **como o pedido chegou** (telefone, WhatsApp, e-mail, indicação, visita ou outro).
3. Marque que **a pessoa pediu a proposta e aceitou receber o contato**. Sem esse pedido, não cadastre.
4. Clique em {{Gravar e preparar a proposta}}. A proposta abre pronta para gerar o PDF.

A proposta cadastrada entra como **Contatada**, com você como responsável. Nenhum e-mail é enviado só por cadastrar.

>! Não marcamos aceite de novidades para quem foi cadastrado pela equipe. Só mande divulgação para quem aparece com **Aceita novidades: Sim**.

## Gerar e enviar a proposta em PDF

1. Abra a proposta e desça até **Gerar proposta em PDF** (ou clique em {{Preparar proposta}}).
2. Escolha o modelo: **Síndico e condomínio** ou **Administradora**. Trocar o modelo recarrega os textos padrão.
3. Revise o assunto, a validade, a apresentação, as atividades (uma por linha), o valor, a forma de pagamento e as observações.
4. Clique em {{Gerar PDF para conferir}} e abra a versão em **Versões da proposta** com {{Abrir PDF}}.
5. Se estiver certo, clique em {{Gerar e enviar por e-mail}} e confirme. O PDF vai anexado para o e-mail da pessoa e a situação passa para **Proposta enviada**.

O PDF segue o modelo da Connect: capa, conteúdo, sobre a Connect, diferenciais, cronograma de implementação, mapa de serviços (com os textos e o valor desta proposta), formalização com assinatura e contato.

>! Depois de enviado, o e-mail não volta. Confira o PDF antes.

## Acompanhar

Em **Acompanhamento**, escolha a **Situação** (Nova, Contatada, Qualificada, Proposta enviada, Fechada, Perdida ou Arquivada) e o **Responsável**, e clique em {{Salvar acompanhamento}}. Na primeira vez que a proposta fica **Contatada**, o sistema grava a data do primeiro contato.

>> Veja também: [Propostas no Studio (consulta)](artigo:propostas-do-site).

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- Os pedidos do site e as propostas cadastradas aqui ficam na mesma lista; a coluna do condomínio mostra a origem.
- Cada geração grava uma versão nova do PDF; as versões antigas, inclusive as feitas antes no Studio, continuam na lista.
- Enviar põe o e-mail com o PDF anexado na fila de avisos e muda a situação para Proposta enviada (a não ser que já esteja Fechada, Perdida ou Arquivada).
- Cadastrar, gerar, enviar e mudar a situação entram no histórico do sistema.
- As propostas não são de um condomínio cadastrado, então a escolha do condomínio na barra superior não muda esta lista.
