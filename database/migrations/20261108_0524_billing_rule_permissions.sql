-- Permissões das regras de cobrança e dos índices (ADR 0040); prefixo erp. nega sem registro (ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar regras de cobrança','erp.billing_rules.view','ERP Cobrança','Consultar multa, juros, índice de correção, regra de quebra de acordo e os índices mensais'),
('Gerenciar regras de cobrança','erp.billing_rules.manage','ERP Cobrança','Alterar a regra do condomínio (ou o padrão da Connect, com acesso a todos) e lançar ou importar índices');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.billing_rules.view','erp.billing_rules.manage');
