# ADR 0152 — Plano padrão de receitas e despesas, contas por natureza no lançamento e seletor de competência

- Situação: aceita (10/11/2026)
- Contexto: relato do Djalma em 10/11 ("Novo lançamento" de despesa não salva; competência como calendário de mês)

## Contexto

CONFIRMADO num banco novo com todas as migrations (MariaDB local, `repro_test`): o plano padrão
(`condominium_id` NULL) só tinha as contas patrimoniais 7/8/9 criadas pelo ADR 0167. Nenhuma conta de receita
ou despesa. O campo "Conta do plano de contas" de Lançamentos filtrava só `kind = analitica`, então oferecia
"7.2 Contas a receber", "8.1 Contas a pagar", "8.2 Fundo de reserva" etc. Ao lançar a despesa "Manutenção
elétrica" com a conta 8.1 (passivo), o motor recusava com "A conta precisa ter a mesma natureza do lançamento",
e em Contas a pagar a lista ficava vazia. Centro de custo e conta bancária não eram a causa: o primeiro é
opcional e a conta bancária só é exigida quando "Pago em" está preenchido.

A competência era um texto AAAA-MM; os `input type="month"` de outras telas não têm calendário no Safari.

## Decisão

- Migration `20261110_0709_chart_default_income_expense` cria no plano padrão `1 Receitas` e `2 Despesas` com as
  contas mais comuns de condomínio, incluindo `2.04 Manutenção e conservação` (sintética) e `2.04.01 Manutenção
  elétrica`. Idempotente: só entra código que não existe no plano padrão, e filha só com mãe sintética da mesma
  natureza; quem já tem o próprio 1 ou 2 fica com o seu. A reversão apaga só as contas criadas por ela (nota
  "Plano padrão Connect (ADR 0152).") que ninguém usa.
- `ref_where` aceita lista de valores (IN). Lançamentos e Lançamentos fixos filtram
  `nature IN (receita, despesa)`; Contas a pagar/receber continuam com a natureza da visão.
- As opções de `ref` levam o condomínio (`data-condominium`) e os valores de `ref_match` (`data-match-nature`);
  o erp.js esconde as que não combinam com o condomínio e a natureza escolhidos. O servidor continua validando.
- `ref_empty` explica a lista vazia com link para o Plano de contas.
- O alerta do formulário lista os campos com problema e o motivo, com link para cada campo.
- Campos de competência com `'month' => true` (e todo `input type="month"`) ganham o seletor de mês e ano do
  erp.js: campo "nov/2026" com botão de calendário, grade de 12 meses, setas de ano, "Este mês" e "Limpar",
  teclado (setas, Esc) e `min`/`max`. O campo original fica oculto com o nome e o valor AAAA-MM, então APIs,
  filtros e scripts não mudam. O validador aceita também 11/2026 e nov/2026 e grava AAAA-MM.

## Consequências

- Banco existente: rodar `composer db:migrate` cria as contas que faltam sem tocar nas do usuário.
- Seletores de print da Central de Ajuda para competência apontam para `.erp-month` (o campo original fica oculto).
- Testes: `ChartDefaultEntriesTest` (integração), `MonthCompetenceFieldTest` (unit) e ajustes em
  `EntriesDefinitionTest` e `RecurringEntriesDefinitionTest` (11/2026 passou a valer).
- PENDENTE: o plano padrão é uma sugestão; a Connect pode renomear ou inativar contas pela tela AP-160.
