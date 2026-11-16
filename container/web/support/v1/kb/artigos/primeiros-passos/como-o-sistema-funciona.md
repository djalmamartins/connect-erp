---
codigo:
titulo: Como o Connect ERP funciona: o guia para quem está chegando
resumo: Para quem vai usar o sistema pela primeira vez. Explica como as partes se ligam (condomínio, unidades, pessoas, lançamentos, cobrança e prestação de contas) e o que acontece por trás das ações mais comuns, como registrar um pagamento ou emitir uma fatura.
categoria: primeiros-passos
rota: /dashboard
menu:
icone: reader-outline
situacao: guia
relacionados: entrar-no-sistema, escolher-o-condominio, encontrar-uma-tela, dashboard, condominios, lancamentos
palavras: começar, primeira vez, iniciante, como funciona, o que acontece, por trás, treinamento, visão geral, fluxo, passo a passo, novo usuário
rascunho: nao
manual: sim
---

Este guia é para quem está chegando. Ele não ensina tela por tela (cada tela tem o próprio artigo e, no ERP, o quadro **Sobre esta tela** logo abaixo do título). Ele mostra como as partes do sistema se ligam e o que acontece por trás quando você grava alguma coisa. Com isso em mente, qualquer tela fica fácil.

## A ideia central: tudo pertence a um condomínio

A Connect é uma administradora e cuida de vários condomínios. No sistema, quase tudo pertence a um condomínio: as unidades, as pessoas, as contas bancárias, os lançamentos, as cobranças, as reservas e os relatórios.

- O **condomínio da barra superior** é o condomínio em que você está trabalhando. As telas mostram só os dados dele. Em **Todos os condomínios**, você vê a carteira inteira.
- Cada pessoa da equipe só enxerga os condomínios liberados na conta dela. Isso é conferido pelo servidor em cada tela e em cada gravação, não só escondido no menu.

>> Tudo sobre [escolher o condomínio](artigo:escolher-o-condominio)

## A ordem certa para começar um condomínio

Os cadastros dependem uns dos outros. Por isso, um condomínio novo segue sempre esta ordem (o próprio sistema abre um guia com estes passos quando você salva o condomínio):

1. **Condomínio**: os dados, o endereço e o contrato. Ao salvar, ele já nasce com o checklist de chegada.
2. **Unidades**: cada apartamento, casa, sala ou loja. Dá para criar o prédio inteiro de uma vez pelo cadastro rápido.
3. **Proprietários e inquilinos**: as pessoas de cada unidade e quem recebe o boleto.
4. **Síndico e conselho**: quem representa o condomínio, com o mandato.
5. **Áreas**: vagas, depósitos e boxes de cada unidade; depois, as áreas comuns que podem ser reservadas.
6. **Checklist de chegada**: o que ainda falta, com os prazos.

Para o financeiro, também é preciso ter a **conta bancária** do condomínio ([[Financeiro > Cadastros financeiros > Contas bancárias]]) e o **plano de contas** ([[Configurações > Financeiro e parâmetros > Plano de Contas]]), que já vem com um padrão para todos os condomínios.

>> Tudo sobre [cadastrar um condomínio](artigo:condominios)

## O coração do financeiro: o lançamento

Todo dinheiro que entra ou sai do condomínio é um **lançamento**: uma **receita** (a taxa de condomínio de uma unidade, por exemplo) ou uma **despesa** (a conta de luz, o salário do zelador). Cada lançamento tem:

- a **competência**: o mês a que ele se refere (a taxa de novembro, a conta de luz de outubro);
- o **vencimento**: até quando precisa ser pago;
- o **pago em** e a **conta bancária**: preenchidos quando o dinheiro de fato entra ou sai. Enquanto o **Pago em** está vazio, o lançamento está **em aberto**;
- a **conta do plano de contas**: em que grupo ele entra no balancete (ex.: 2.04.01 Manutenção elétrica).

O ponto mais importante para entender o sistema: **os relatórios não guardam números próprios**. O saldo da conta bancária, o Dashboard, o balancete, o livro caixa, o balanço e a inadimplência são todos calculados a partir dos lançamentos, na hora em que você abre a tela. Por isso, basta lançar certo uma vez: tudo o mais se atualiza sozinho.

- **Contas a Pagar** e **Contas a Receber** não são cadastros separados: são os lançamentos em aberto, de despesa e de receita, vistos por vencimento.
- O **saldo de cada conta bancária** é o saldo inicial mais as receitas pagas menos as despesas pagas naquela conta.

## O que acontece quando você registra um pagamento

Exemplo: chegou a conta de manutenção do elevador, de R$ 1.200,00, que vence dia 10.

1. **Ao cadastrar a despesa** em [[Financeiro > Contas e pagamentos > Contas a Pagar]] (ou em [[Financeiro > Lançamentos > Lançar Despesa]]), o sistema confere os campos, o seu acesso ao condomínio, se a conta escolhida é de despesa e se o mês da competência não está fechado. Grava o lançamento em aberto e registra no histórico quem cadastrou.
   - A despesa já aparece em **Contas a Pagar**, no cartão **A pagar em 7 dias** do Dashboard quando faltar uma semana, e entra nas despesas do mês no balancete. O saldo do banco ainda não muda: o dinheiro não saiu.
2. **No dia em que você paga**, abre a conta e preenche **Pago em** e a **Conta bancária** (sem a conta bancária o sistema não deixa dar a baixa).
   - A despesa sai de Contas a Pagar (continua em Lançamentos), o valor sai do saldo daquela conta bancária, entra no livro caixa do dia do pagamento e no **pago** do mês no Dashboard. A mudança fica no histórico.
3. **Se errou**, dá para editar ou estornar a baixa enquanto o mês estiver aberto. Uma conta conciliada com o extrato do banco, ou com recibo emitido, fica protegida: primeiro desfaça a conciliação ou cancele o recibo.

>! Lançamento pago **sem conta bancária** faz o caixa não bater com o banco. Por isso a baixa sempre pede a conta.

## O que acontece quando você cobra os moradores

1. **A fatura** ([[Financeiro > Contas e pagamentos > Faturas]]) cobra um tipo de fatura (a taxa de condomínio, por exemplo) de todas as unidades ativas numa competência. Primeiro ela nasce como **rascunho**, com um valor por unidade: fixo ou rateado pela **fração ideal** de cada unidade. Você confere e ajusta.
2. **Ao emitir**, cada unidade ganha uma **conta a receber** própria, todas de uma vez (se uma falhar, nenhuma é criada). O sistema não deixa emitir a mesma fatura duas vezes.
3. **O boleto** ([[Cobrança > Boletos]]) é pedido ao banco para cada conta a receber. O título é guardado antes de falar com o banco e só fica **registrado** quando o banco confirma. Pedir de novo devolve o mesmo boleto, sem duplicar.
4. **Quando o morador paga**, o banco avisa (pelo arquivo de retorno ou pela notificação do banco). O sistema consulta o banco para confirmar e só dá a baixa sozinho quando o valor pago é igual ao do boleto. Valor diferente, boleto cancelado ou mês fechado vão para a fila de divergências, para alguém conferir: o sistema nunca dá baixa escondido.
5. **Quem não paga** aparece na inadimplência, com os encargos de atraso, e pode virar um **acordo** ([[Cobrança > Acordos]]), que gera as parcelas como novas contas a receber.

>! No ambiente de teste, nenhum boleto é registrado de verdade: o banco é simulado. Nenhuma cobrança real sai sem a liberação da Connect.

## O fim do mês: fechar e prestar contas

1. **Antes de fechar**, a tela [[Financeiro > Fechamento > Fechamento Mensal]] mostra o que precisa de atenção: lançamentos pagos sem conta bancária, contas em aberto, extrato sem conciliar, mês anterior aberto.
2. **Ao fechar**, o sistema guarda um resumo do mês e **trava** os lançamentos daquela competência: ninguém inclui, altera ou exclui nada dela. Isso garante que a prestação de contas enviada ao conselho não muda depois.
3. **Para corrigir**, alguém com permissão reabre o mês informando o motivo. Fechar e reabrir ficam no histórico.
4. **A prestação de contas** ([[Prestação de contas > Prestação de contas]]) junta o balancete, os demonstrativos e o livro caixa do mês, todos calculados dos mesmos lançamentos.

## Regras que valem para o sistema todo

- **Nada some de verdade.** Excluir tira o registro das listas, mas ele continua guardado com o histórico. Para quem saiu ou deixou de valer, prefira **inativar**.
- **Tudo tem histórico.** Cada inclusão, alteração e exclusão registra quem fez, quando e o que mudou. Dados sensíveis aparecem mascarados no histórico.
- **O que está ligado não pode ser excluído.** Uma unidade com moradores, um fornecedor com despesas ou uma conta do plano com contas-filhas ficam protegidos.
- **Sem repetição.** O sistema recusa duplicidades: o mesmo CNPJ de condomínio, a mesma unidade, a mesma placa, a mesma fatura no mesmo mês.
- **Mês fechado é mês travado.** Para mudar qualquer lançamento dele, reabra o mês com motivo.
- **Erro mostra o que corrigir.** Se algo estiver errado ao salvar, nada é gravado, e o formulário marca cada campo com a explicação.
- **Toda lista se baixa.** O botão **Download**, no alto das listas e dos relatórios, baixa o que está na tela, com os mesmos filtros, em **PDF** (cabeçalho da Connect, condomínio, título, data, site e número da página; abre numa aba do navegador para você ver antes, e de lá imprime ou baixa), em **Excel** (os valores viram número e somam na planilha) ou em **CSV**. Cada download fica na auditoria, com no máximo 30 por hora por pessoa.

## Quando tiver dúvida

- Leia o quadro **Sobre esta tela**, logo abaixo do título de cada tela do ERP. Ele diz para que a tela serve e o que acontece por trás. O link no fim do quadro abre o guia completo daquela tela, com as telas marcadas.
- No menu **Suporte** (ícone ?) da barra superior, **Ajuda desta página** abre o mesmo guia, e **Abrir chamado** fala com a equipe.

>> Tudo sobre [pedir ajuda](artigo:pedir-ajuda)
