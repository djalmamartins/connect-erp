---
codigo: CN-008
titulo: Controlar os honorários e pedir a nota fiscal de cada condomínio
resumo: Cadastre o valor mensal que cada condomínio paga à Connect pela administração, acompanhe o total do mês e peça a nota fiscal de serviço (NFS-e) de cada um.
categoria: condominios
rota: /condominios/honorarios
menu: Condomínios > Honorários
icone: wallet-outline
situacao: funciona
relacionados: condominios
palavras: honorários, taxa de administração, mensalidade, nota fiscal, NFS-e, CN-008
rascunho: nao
---

Cadastre o valor mensal que cada condomínio paga à Connect pela administração, acompanhe o total do mês e peça a nota fiscal de serviço (NFS-e) de cada um.

## Onde fica

No menu lateral, abra [[Condomínios > Honorários]]. A tela lista todos os condomínios que você administra, com o valor mensal, a situação do contrato e a nota da competência escolhida. No alto aparecem o total mensal dos contratos ativos, quantos contratos estão ativos e quantas notas já foram pedidas no mês.

## Como cadastrar os honorários

1. Na linha do condomínio, clique em {{Cadastrar}} (ou {{Abrir}}, se ele já tiver contrato).
2. Preencha **Valor mensal**, **Dia de vencimento** (1 a 28) e **Início do contrato**. O **Fim** é opcional.
3. Confira a **Descrição do serviço na nota**. Ela vem como "Honorários de administração condominial" e vai na nota fiscal.
4. Escolha a **Situação**: Ativo, Suspenso ou Encerrado. Só os contratos ativos entram no total mensal.
5. Clique em {{Salvar honorários}}.

## Ligar e desligar as notas fiscais

As notas fiscais vêm **desligadas**. Enquanto estiverem desligadas, nenhum pedido de nota é aceito e o botão {{Solicitar nota}} não aparece.

Para ligar, abra [[Configurações > Financeiro]] e marque **Notas fiscais (NFS-e) ligadas**. Só quem acessa todos os condomínios pode mudar essa chave. Para desligar, desmarque a mesma opção.

## Como pedir a nota do mês

1. Escolha a **Competência** no alto da lista e clique em {{Ver}}.
2. Na linha do condomínio, clique em {{Solicitar nota}} e confirme.
3. A nota fica com a situação **Solicitada**, com o valor e a descrição do contrato naquele momento.

Cada condomínio tem um pedido por competência. O botão não aparece quando o contrato não está ativo ou quando a competência fica fora do período do contrato.

Nesta etapa o pedido fica registrado no sistema. O envio à Receita, pela API Nacional da NFS-e com o certificado digital da Connect, entra na próxima etapa do módulo.

## Cancelar um pedido

Abra o condomínio, vá em **Pedidos de nota**, escreva o motivo e clique em {{Cancelar}}. A competência fica livre para um novo pedido. Uma nota já emitida não é cancelada por aqui.

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- Cada condomínio tem no máximo um contrato de administração; salvar de novo atualiza o mesmo contrato.
- Pedir a nota só registra o pedido, com o valor e a descrição do contrato. O envio à prefeitura é uma etapa que ainda vai ser ligada, e enquanto as notas fiscais estiverem desligadas nas configurações, nenhum pedido é aceito.
- Só pode haver um pedido aberto por contrato e mês. O cancelamento fica registrado.
- Os honorários não geram lançamento no financeiro do condomínio por esta tela.
- Toda gravação entra no histórico.

## Quem pode usar

Consultar exige a permissão **Visualizar honorários**. Cadastrar, pedir e cancelar notas exige **Gerenciar honorários**. Toda alteração fica registrada na auditoria.
