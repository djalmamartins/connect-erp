-- Reverte 20261108_0544. Antes de apagar o papel, tira o acesso de quem o tinha: o usuário fica sem papel,
-- e sem papel não há acesso (fail-closed). O acesso a condomínios desses usuários também sai.
DELETE uca FROM `user_condominium_access` uca JOIN `erp_partner_agency_users` pu ON pu.user_id = uca.user_id;
DELETE ur FROM `access_user_roles` ur JOIN `access_roles` r ON r.id = ur.role_id WHERE r.slug = 'erp_partner';
DELETE FROM `access_roles` WHERE `slug` = 'erp_partner';
DROP TABLE IF EXISTS `erp_resident_portal_permissions`;
DROP TABLE IF EXISTS `erp_resident_invitations`;
DROP TABLE IF EXISTS `erp_partner_agency_users`;
DROP TABLE IF EXISTS `erp_partner_agencies`;
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.access_admin.view','erp.access_admin.manage','erp.partner.view');
