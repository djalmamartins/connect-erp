-- Reverte 20261108_0529: remove as quatro tabelas e as permissões (os arquivos do Disco Virtual continuam).
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.delivery_notices.view','erp.delivery_notices.manage','erp.check_copies.view',
  'erp.check_copies.manage','erp.commitments.view','erp.commitments.manage','erp.pouches.view','erp.pouches.manage');
DROP TABLE IF EXISTS `erp_pouches`;
DROP TABLE IF EXISTS `erp_commitments`;
DROP TABLE IF EXISTS `erp_check_copies`;
DROP TABLE IF EXISTS `erp_delivery_notices`;
