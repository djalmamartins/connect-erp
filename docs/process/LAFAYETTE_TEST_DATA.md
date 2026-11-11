# Condomínio de teste Lafayette

Pedido do Djalma (11/11/2026): um condomínio chamado Lafayette com seis meses de dados fictícios em todos os
módulos, para testar o sistema e conferir se os valores fecham.

## Como usar (no Mac, banco do laboratório)

```
ERP_DEMO_DATA=true MOVESOS_ENV=lab ERP_DEMO_DB=connect_legacy php tools/lab-lafayette.php            # cria e confere
ERP_DEMO_DATA=true MOVESOS_ENV=lab ERP_DEMO_DB=connect_legacy php tools/lab-lafayette.php --conferir # só confere
ERP_DEMO_DATA=true MOVESOS_ENV=lab ERP_DEMO_DB=connect_legacy php tools/lab-lafayette.php --remover  # apaga para criar de novo
```

- Mesma trava dos dados de exemplo (ADR 0162): sem `ERP_DEMO_DATA=true`, fora de lab/dev ou em banco sem marca
  de laboratório (ou sem o nome exato em `ERP_DEMO_DB`), o comando recusa.
- `--usuario=ID` escolhe quem aparece na auditoria. Sem ele, usa o primeiro usuário com acesso a todos os condomínios.
- Com `MOVESOS_ENV=lab` o Config exige `MOVESOS_DB_HOST/USER/PASS/NAME` no ambiente (no Mac vêm do `.env`).
- A saída lista o que foi criado e cada conferência com esperado e encontrado. Código 0 = tudo fechou; 2 = alguma
  conferência não fechou.

## O que é criado

Os seis meses completos antes de hoje (em 11/11/2026: maio a outubro de 2026). Tudo passa pelos services das
telas (mesmas validações, escopo e auditoria), em `Source\Services\Erp\LafayetteSample`.

| Área | Dados |
|---|---|
| Condomínio | Lafayette (Condomínio Edifício Lafayette), CNPJ fictício, Ribeirão Preto/SP, vencimento dia 10 |
| Unidades | 16 apartamentos pelo cadastro rápido (blocos A e B, 4 andares, 2 por andar); 4º andar é cobertura (84 m², 7%), os demais 72 m² e 6% — soma 100% |
| Pessoas | 16 proprietários (um com duas unidades), 4 inquilinos, síndico, subsíndico, 2 conselheiros consultivos e 1 fiscal |
| Cadastros | 4 funcionários, 7 fornecedores, advogada, contato útil, 10 veículos, 10 vagas e 1 depósito, 3 visitantes, 3 animais, salão e churrasqueira, 3 itens de patrimônio, portaria |
| Água | tarifa progressiva em 3 faixas, relógio em cada unidade, 96 leituras e a cobrança do consumo todo mês |
| Receitas | taxa condominial de R$ 19.200,00 e fundo de reserva de R$ 1.920,00 rateados pela fração ideal; água; reserva do salão; multa e juros de um Pix atrasado |
| Cobrança | boleto no banco de testes (sandbox) para cada conta a receber, retorno do banco com os pagamentos |
| Despesas | folha, encargos, água da concessionária, energia, elevadores, jardinagem, tarifas, seguro anual, troca da bomba; limpeza e honorários pelos lançamentos fixos. As do último mês ficam a pagar |
| Bancos | conta movimento (R$ 25.000 de abertura), poupança do fundo (R$ 40.000) e CDB ligado à conta movimento; transferência mensal do fundo, aplicação, resgate, rendimento e IR |
| Inadimplência | B-302 fica 3 meses sem pagar e faz acordo em 4 parcelas; A-201 deixa os 2 últimos meses em aberto, com contato de cobrança e promessa; A-102 paga um mês por Pix com 18 dias de atraso |
| Mês corrente | cobrança extra rateando a bomba (R$ 4.800) e multa da ocorrência de barulho, ambas a vencer |
| Operação | assembleia ordinária com presença e votação, comunicado, 2 ocorrências, 2 reservas, mudança, 2 manutenções, entrega, achado, previsão de visita, chamado, quitação e certidão de débitos |
| Fechamento | abertura do balanço (fundo 8.2 = 40.000, resultados acumulados 9.1 = 25.000); os 4 meses mais antigos fechados, os 2 últimos abertos |

Contas do plano: reaproveita a conta do plano padrão com o mesmo código, natureza e tipo; o que faltar entra no
plano próprio do Lafayette (o padrão dos outros condomínios não muda). E-mails em `example.com` (ninguém recebe).

## O que é conferido

Mais de 50 conferências, cada uma com esperado × encontrado:

- cada fatura soma exatamente o valor rateado (taxa e fundo, nos 6 meses);
- água: soma das leituras = contas a receber de água, nenhuma leitura sem cobrança;
- boletos pagos = contas a receber baixadas pelo retorno; nenhuma divergência pendente;
- saldo de cada conta bancária igual em Contas bancárias, em Saldos de contas (AP-073) e na conta direta no banco
  (abertura + recebido − pago ± transferências); transferências e aplicações não mudam o total;
- prestação de contas por caixa (AP-051) com diferença zero;
- balancete (AP-079) e DRE (AP-082) do semestre = soma direta das receitas e despesas;
- fechamento mensal (AP-030) e prestação de contas mensal (AP-101) de cada mês = soma direta;
- inadimplência (AP-033 e relatório AP-057) = contas vencidas em aberto; a unidade do acordo não tem dívida vencida;
- recebimentos (AP-071) = receitas pagas no período;
- Balanço Patrimonial (AP-081): ativo − (passivo + PL) = 0 e disponível = soma dos bancos.

O teste `LafayetteSampleIntegrationTest` cria o Lafayette no banco de teste e exige que todas fechem, apaga e cria
de novo. Se um relatório deixar de bater com os outros, a suíte de integração quebra.

## Limites

- Anexos (nota fiscal, comprovante) e boleto com a marca da Connect estão em outras frentes; quando existirem,
  entram aqui.
- `--remover` apaga as linhas do condomínio e as filhas delas; fornecedores e a advogada (cadastros da
  administradora) e a trilha de auditoria ficam.
