-- Permissões de inadimplência e acordos (AP-033/034, ADR 0039); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar inadimplência e acordos','erp.agreements.view','ERP Cobrança','Consultar as contas vencidas por unidade e os acordos com as parcelas'),
('Gerenciar acordos','erp.agreements.manage','ERP Cobrança','Fazer acordo das contas vencidas de uma unidade e cancelar acordo sem parcela paga');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.agreements.view','erp.agreements.manage');
