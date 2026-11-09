-- Permissões do fechamento mensal (AP-030, ADR 0031); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar fechamento mensal','erp.closing.view','ERP Financeiro','Consultar o resumo e a situação do fechamento de cada competência'),
('Fechar e reabrir competência','erp.closing.manage','ERP Financeiro','Fechar a competência (trava os lançamentos) e reabrir com motivo');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.closing.view','erp.closing.manage');
