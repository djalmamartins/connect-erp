---
codigo: CN-011
titulo: Propostas pedidas pelo site (ERP)
resumo: Veja no ERP os pedidos de proposta feitos no site, saiba quando chega um novo e acompanhe cada um até virar contrato.
categoria: comunicacao
rota: /comunicacao/propostas
menu: Comunicação > Comercial > Propostas do site
icone: document-text-outline
situacao: funciona
relacionados: propostas-do-site, comunicados-gerais
palavras: proposta, pedido de proposta, orçamento, site, comercial, funil, alerta, aviso, sino, novo cliente, CN-011
rascunho: nao
---

Veja no ERP os pedidos de proposta feitos no site, saiba quando chega um novo e acompanhe cada um até virar contrato.

## Onde fica

No menu lateral, abra [[Comunicação > Comercial > Propostas do site]]. Só aparece para quem tem a permissão de **gerenciar propostas**, a mesma do Studio.

Os pedidos são os mesmos de **Studio > Gestão > Propostas**: o que você muda aqui aparece lá, e o contrário também.

## Como saber que chegou uma proposta

Quando alguém preenche **Solicite uma proposta** no site:

1. o **sino** do ERP ganha um aviso com o nome do condomínio e o número de unidades. Clique no aviso para abrir o pedido aqui no ERP;
2. aparece um **número** ao lado de **Propostas do site** no menu (e ao lado de **Comunicação**, com o grupo fechado);
3. no alto da tela aparece o aviso **propostas novas aguardando contato**.

O número e o aviso ficam enquanto houver pedidos com a situação **Nova**. Eles somem sozinhos quando você muda a situação, por exemplo para **Contatada**.

## Acompanhar um pedido

1. Use a busca (nome, e-mail, condomínio ou protocolo) e o filtro de **Situação** e clique em {{Filtrar}}.
2. Clique em {{Abrir}} na linha do pedido.
3. Confira os dados: e-mail, WhatsApp (o link abre a conversa), condomínio, unidades e a necessidade que a pessoa escreveu.
4. Em **Acompanhamento**, escolha a **Situação** (Nova, Contatada, Qualificada, Proposta enviada, Fechada, Perdida ou Arquivada) e o **Responsável**, e clique em {{Salvar acompanhamento}}.

Na primeira vez que o pedido fica **Contatada**, o sistema grava a data do primeiro contato. Toda mudança entra no histórico do sistema.

>! Só mande divulgação para quem aparece com **Aceita novidades: Sim**. Os outros pediram só a proposta.

## Responder com a proposta em PDF

O PDF da proposta é feito no Studio. No pedido, clique em {{Responder no Studio}}: o mesmo pedido abre lá, em **Gerar proposta em PDF**. As respostas já geradas aparecem no ERP, em **Respostas em PDF**, com a data e se foram enviadas.

>> Veja também: [Propostas pedidas pelo site (Studio)](artigo:propostas-do-site).

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- São os mesmos pedidos do Studio > Gestão > Propostas: mudar a situação ou o responsável aqui muda lá também.
- Cada pedido novo manda um aviso no sino para quem pode abrir esta tela. Enquanto houver pedidos na situação Nova, o menu mostra o número deles e a tela mostra um aviso no alto.
- Na primeira vez que o pedido fica Contatada, a data do primeiro contato é gravada. Toda mudança entra no histórico do sistema.
- O PDF da proposta é gerado e enviado pelo Studio. Aqui aparecem as respostas já geradas.
- Os pedidos não são de um condomínio cadastrado, então a escolha do condomínio na barra superior não muda esta lista.
