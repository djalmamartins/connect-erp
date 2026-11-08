-- Permissões de Unidades (AP-006, ADR 0040) sobre o núcleo access_* (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar unidades','erp.units.view','ERP Condomínios','Consultar as unidades dos condomínios do seu escopo'),
('Gerenciar unidades','erp.units.manage','ERP Condomínios','Cadastrar, alterar e excluir unidades');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.units.view','erp.units.manage');
