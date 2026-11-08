-- Permissões de lançamentos (AP-020, ADR 0022); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar lançamentos','erp.entries.view','ERP Financeiro','Consultar receitas e despesas dos condomínios do seu escopo'),
('Gerenciar lançamentos','erp.entries.manage','ERP Financeiro','Lançar, alterar, baixar e cancelar receitas e despesas');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.entries.view','erp.entries.manage');
