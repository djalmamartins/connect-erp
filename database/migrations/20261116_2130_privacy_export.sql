-- Relatório dos dados pessoais de um titular (LGPD), ADR 0328.
-- Permissão erp.privacy.export: nasce dada aos papéis que já gerenciam usuários do ERP (erp.users.manage). Idempotente.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Relatório de dados pessoais (LGPD)','erp.privacy.export','ERP Usuários','Buscar tudo o que o sistema guarda de uma pessoa, por CPF/CNPJ ou e-mail, para atender o pedido do titular');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT rp.role_id, t.id FROM `access_role_permissions` rp
JOIN `access_permissions` s ON s.id = rp.permission_id AND s.slug = 'erp.users.manage'
JOIN `access_permissions` t ON t.slug = 'erp.privacy.export';
