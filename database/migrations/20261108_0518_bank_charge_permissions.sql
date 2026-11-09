-- Permissões de boletos e retornos (ADR 0035); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar boletos','erp.bank_charges.view','ERP Cobrança','Consultar boletos, arquivos de retorno e a fila de divergências'),
('Gerenciar boletos','erp.bank_charges.manage','ERP Cobrança','Emitir, consultar no banco e cancelar boletos; importar retornos; resolver divergências');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.bank_charges.view','erp.bank_charges.manage');
