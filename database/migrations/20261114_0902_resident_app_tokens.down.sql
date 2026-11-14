-- Reverte 20261114_0902: tira as sessões do app do morador. Todos os aparelhos precisam entrar de novo.
DROP TABLE IF EXISTS `erp_resident_app_challenges`;
DROP TABLE IF EXISTS `erp_resident_app_tokens`;
