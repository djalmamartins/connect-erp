# Condomínios de teste do rateio (ADR 0640)

Nove condomínios fictícios, um para cada forma de rateio e um misto, com movimento de 01/01/2026 até hoje e uma
auditoria contábil que confere se todos os números fecham.

| Condomínio | Unidades | Como rateia |
|---|---|---|
| Condomínio Por Fração Ideal | 48 apartamentos | Taxa, fundo e cota extra pela fração ideal (pela área) |
| Condomínio Igualitário | 30 casas | Valor fixo igual por casa |
| Condomínio Por Unidade | 36 apartamentos (1 isento) | Total ÷ unidades que participam |
| Condomínio Por Consumo Individual | 32 apartamentos | Taxa fixa + água e gás pelas leituras |
| Condomínio Por Bloco/Torre | Torres A, B (20) e C (10) | Geral pela fração + despesa de cada torre só nela |
| Condomínio Por Vaga de Garagem | 30 apartamentos | Garagem pelo número de vagas (sem vaga não paga) |
| Condomínio Por Percentual Definido | 4 lojas + 16 salas | Percentual de cada unidade (8% e 4,25%) |
| Condomínio Personalizado | 24 apartamentos | Coberturas por percentual e academia por valor escolhido |
| Condomínio Misto | 40 apartamentos | Fração + fundo igual + água + garagem por vaga |

Cada condomínio tem moradores (proprietários e inquilinos), plano de contas, conta bancária e fundo de reserva, faturas
mensais com boletos no sandbox, pagamentos no prazo, por Pix e atrasados com multa e juros, despesas, transferência para
o fundo, rendimento, um acordo com contas de meses fechados sem reabrir nenhum mês (ADR 0642; no Percentual Definido o acordo é quebrado), fechamento de cada mês no dia 5 e a
prestação de contas publicada.

## Como rodar (Mac, lab)

```sh
docker compose exec app sh -c 'MOVESOS_ENV=lab ERP_DEMO_DATA=true ERP_DEMO_DB="$MOVESOS_DB_NAME" php tools/lab-rateios.php --relatorio=storage/rateios.md'
```

- Sem opção: cria os 9 condomínios (se já existirem, só confere) e roda a auditoria. Leva uns 2 minutos.
- `--conferir`: refaz só a auditoria.
- `--remover`: apaga os 9 condomínios e tudo deles.
- `--usuario=ID`: usuário que grava (padrão: o primeiro administrador).
- `--relatorio=arquivo.md`: grava a auditoria em Markdown.

O script só roda com o guarda dos dados de demonstração: `ERP_DEMO_DATA=true`, ambiente `lab` ou `dev` e o banco igual a
`ERP_DEMO_DB`. Nunca rode na produção. Sai com código 0 quando tudo fecha e 2 quando alguma conferência falha.

## O que a auditoria confere

Rateio (soma das unidades = total, cada unidade na sua base, participantes certos), consumo (leitura × tarifa por faixa),
boletos (pagos, em aberto, multa e juros refeitos à mão), acordos (originais inativos, parcelas, quebra), bancos (saldo =
entradas − saídas), fechamentos (ordem, foto, prestação publicada intacta), inadimplência de hoje e de cada fim de mês,
extrato, balancete, DRE e balanço patrimonial.
