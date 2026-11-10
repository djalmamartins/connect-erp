-- Reverte 20261109_0706: remove os lançamentos patrimoniais (ADR 0169). Os fundos voltam a valer só o saldo
-- inicial (AP-161). As receitas, as despesas e o banco não mudam. Antes de rodar, exporte os lançamentos se
-- quiser guardá-los.
DROP TABLE IF EXISTS `erp_patrimonial_entries`;
