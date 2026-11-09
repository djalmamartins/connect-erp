-- Reverte 20261108_0525: remove as tabelas e as permissões da portaria.
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.gatehouses.view','erp.gatehouses.manage','erp.visit_forecasts.view','erp.visit_forecasts.manage',
  'erp.access_logs.view','erp.access_logs.manage','erp.lost_found.view','erp.lost_found.manage','erp.deliveries.view','erp.deliveries.manage');
DROP TABLE IF EXISTS `erp_deliveries`;
DROP TABLE IF EXISTS `erp_lost_found`;
DROP TABLE IF EXISTS `erp_access_logs`;
DROP TABLE IF EXISTS `erp_visit_forecasts`;
DROP TABLE IF EXISTS `erp_gatehouses`;
