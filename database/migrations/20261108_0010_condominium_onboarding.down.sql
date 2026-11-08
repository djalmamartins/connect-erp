-- Reverte o checklist de chegada (apaga o histórico de itens dos condomínios).
DELETE rp FROM `access_role_permissions` rp JOIN `access_permissions` p ON p.id = rp.permission_id WHERE p.slug = 'erp.onboarding.catalog';
DELETE FROM `access_permissions` WHERE `slug` = 'erp.onboarding.catalog';
DROP TABLE IF EXISTS `condominium_onboarding_items`;
DROP TABLE IF EXISTS `onboarding_item_templates`;
