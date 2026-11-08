-- Permissões do plano de contas (AP-160, ADR 0019); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar plano de contas','erp.chart.view','ERP Financeiro','Consultar o plano de contas padrão e o dos condomínios do seu escopo'),
('Gerenciar plano de contas','erp.chart.manage','ERP Financeiro','Cadastrar, alterar e excluir contas do plano de contas');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.chart.view','erp.chart.manage');
