-- Permissões de centros de custo e contas bancárias (CN-002, CN-003, ADR 0021); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar centros de custo','erp.cost_centers.view','ERP Financeiro','Consultar os centros de custo padrão e os dos condomínios do seu escopo'),
('Gerenciar centros de custo','erp.cost_centers.manage','ERP Financeiro','Cadastrar, alterar e excluir centros de custo'),
('Visualizar contas bancárias','erp.bank_accounts.view','ERP Financeiro','Consultar as contas bancárias dos condomínios do seu escopo'),
('Gerenciar contas bancárias','erp.bank_accounts.manage','ERP Financeiro','Cadastrar, alterar e excluir contas bancárias');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin')
  AND p.slug IN ('erp.cost_centers.view','erp.cost_centers.manage','erp.bank_accounts.view','erp.bank_accounts.manage');
