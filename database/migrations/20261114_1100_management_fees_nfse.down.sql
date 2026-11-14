-- Reverte os honorários e os pedidos de NFS-e (ADR 0402); apaga os contratos e os pedidos.
DELETE rp FROM `access_role_permissions` rp JOIN `access_permissions` p ON p.id = rp.permission_id
  WHERE p.slug IN ('erp.management_fees.view','erp.management_fees.manage');
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.management_fees.view','erp.management_fees.manage');
DELETE FROM `erp_settings` WHERE `setting_key` = 'nfse.enabled';
DROP TABLE IF EXISTS `erp_nfse_requests`;
DROP TABLE IF EXISTS `erp_management_contracts`;
