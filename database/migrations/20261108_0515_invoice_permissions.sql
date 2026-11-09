-- Permissões das faturas (AP-032, ADR 0033); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar faturas','erp.invoices.view','ERP Financeiro','Consultar as faturas e os itens por unidade dos condomínios do seu escopo'),
('Gerenciar faturas','erp.invoices.manage','ERP Financeiro','Preparar, ajustar, emitir (gera contas a receber) e cancelar faturas');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.invoices.view','erp.invoices.manage');
