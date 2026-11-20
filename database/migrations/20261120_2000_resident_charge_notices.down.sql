-- Reverte 20261120_2000: some só a marca dos avisos já enviados (o próximo cron pode repetir um aviso do dia).
DROP TABLE IF EXISTS `erp_resident_charge_notices`;
