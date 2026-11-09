-- Reverte 20261108_0527: remove leituras, tarifas, relógios e as permissões (as contas a receber geradas continuam).
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.meters.view','erp.meters.manage','erp.tariffs.view','erp.tariffs.manage',
  'erp.meter_readings.view','erp.meter_readings.manage');
DROP TABLE IF EXISTS `erp_meter_readings`;
DROP TABLE IF EXISTS `erp_tariffs`;
DROP TABLE IF EXISTS `erp_meters`;
