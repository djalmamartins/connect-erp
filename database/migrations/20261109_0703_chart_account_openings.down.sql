-- Reverte 20261109_0703: remove o saldo inicial do plano de contas (AP-161). Os lançamentos não mudam.
DROP TABLE IF EXISTS `erp_chart_account_openings`;
