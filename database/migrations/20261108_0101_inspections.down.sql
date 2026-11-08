-- Reversão da vistoria presencial. Apaga as vistorias registradas: rodar só com backup.
-- As fotos em storage/private/inspections não são apagadas por esta reversão.
DELETE rp FROM `access_role_permissions` rp JOIN `access_permissions` p ON p.id = rp.permission_id
WHERE p.slug IN ('erp.inspections.view','erp.inspections.perform','erp.inspections.manage');
DELETE o FROM `access_user_overrides` o JOIN `access_permissions` p ON p.id = o.permission_id
WHERE p.slug IN ('erp.inspections.view','erp.inspections.perform','erp.inspections.manage');
DELETE FROM `access_permissions` WHERE slug IN ('erp.inspections.view','erp.inspections.perform','erp.inspections.manage');

DROP TABLE IF EXISTS `inspection_report_deliveries`;
DROP TABLE IF EXISTS `inspection_photos`;
DROP TABLE IF EXISTS `inspection_answers`;
DROP TABLE IF EXISTS `inspections`;
DROP TABLE IF EXISTS `inspection_plans`;
DROP TABLE IF EXISTS `inspection_template_items`;
DROP TABLE IF EXISTS `inspection_templates`;
DROP TABLE IF EXISTS `condominium_geolocations`;
