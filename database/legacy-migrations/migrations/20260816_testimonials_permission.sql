INSERT INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Gerenciar depoimentos','testimonials.manage','Conteúdo','Cadastrar, editar, publicar e excluir depoimentos exibidos no site')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`);

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r CROSS JOIN access_permissions p
WHERE p.slug='testimonials.manage' AND r.slug IN ('developer','super_admin','client_admin','manager');
