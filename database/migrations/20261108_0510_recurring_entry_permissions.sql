-- Permissões de lançamentos fixos (AP-021, ADR 0027); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar lançamentos fixos','erp.recurring_entries.view','ERP Financeiro','Consultar os lançamentos fixos dos condomínios do seu escopo'),
('Gerenciar lançamentos fixos','erp.recurring_entries.manage','ERP Financeiro','Cadastrar lançamentos fixos e gerar os lançamentos do mês');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.recurring_entries.view','erp.recurring_entries.manage');
