---
codigo: CN-012
titulo: Conferir as unidades da imobiliária e se estão em dia
resumo: Tela da imobiliária parceira, só de consulta: as unidades que ela administra em cada condomínio, se o condomínio está em dia ou em atraso e quem mora em cada uma.
categoria: condominios
rota: /condominios/imobiliaria
menu: Condomínios > Unidades da imobiliária
icone: business-outline
situacao: funciona
relacionados: condominios
palavras: imobiliária, parceira, inquilino, aluguel, em dia, atraso, inadimplência, CN-012
manual: sim
rascunho: nao
---

Tela da imobiliária parceira, só de consulta: as unidades que ela administra em cada condomínio, se o condomínio está em dia ou em atraso e quem mora em cada uma.

## Quem vê esta tela

Só a conta de uma **imobiliária parceira**. Quando a pessoa da imobiliária entra no ERP, o menu mostra apenas esta tela. A equipe da Connect não a vê no menu.

## Onde fica

No menu lateral, abra [[Condomínios > Unidades da imobiliária]]. No alto aparecem quantas unidades a imobiliária administra, quantas estão em atraso e o valor em atraso. Depois vem um quadro por condomínio, com a lista das unidades.

## O que cada coluna mostra

- **Situação**: **Em dia** ou **Em atraso**. Em atraso quer dizer que a unidade tem conta do condomínio vencida e ainda não paga. A conta que vence hoje ainda não conta como atraso.
- **Contas vencidas** e **Valor vencido**: quantas contas estão vencidas e a soma delas.
- **Vencida desde**: o vencimento da conta em atraso mais antiga.
- **Último pagamento**: a data do último pagamento registrado da unidade.
- **Moradores**: nome, vínculo (proprietário, inquilino ou morador) e o contato com parte escondida.

A tela olha os últimos 24 meses. Boletos, acordos e o detalhe de cada conta continuam com a Connect e com o morador, no app.

## Como a Connect libera as unidades

1. Em [[Configurações > Usuários de imobiliárias]], a equipe cadastra a imobiliária, liga a conta da pessoa e libera os condomínios.
2. Na ficha de cada unidade (ou na edição em massa da lista de unidades), a equipe escolhe a **Imobiliária** que administra aquela unidade.
3. Só as unidades marcadas com a imobiliária aparecem aqui. Um condomínio liberado sem nenhuma unidade marcada aparece vazio.

## Cuidados

- É só consulta: a imobiliária não altera nada no ERP.
- Os dados seguem a LGPD: só o necessário para conferir se o condomínio está pago, com e-mail, telefone e documento mascarados.
- Se a imobiliária for desativada, a tela avisa e não mostra mais nenhuma unidade.

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- Aparecem só as unidades marcadas com a sua imobiliária na ficha da unidade, nos condomínios que a Connect liberou para a sua conta.
- Em atraso é a unidade com conta do condomínio vencida antes de hoje e ainda não paga. A tela olha os últimos 24 meses.
- Não aparecem lançamentos, boletos, pagador nem documentos. E-mail, telefone e documento dos moradores vêm com parte escondida.
- Nada é alterado por esta tela. Se a imobiliária for desativada, a tela deixa de mostrar as unidades.
