-- Permissões de tipos de faturas (AP-168, ADR 0020); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar tipos de faturas','erp.invoice_types.view','ERP Financeiro','Consultar os tipos de faturas padrão e os dos condomínios do seu escopo'),
('Gerenciar tipos de faturas','erp.invoice_types.manage','ERP Financeiro','Cadastrar, alterar e excluir tipos de faturas');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.invoice_types.view','erp.invoice_types.manage');
