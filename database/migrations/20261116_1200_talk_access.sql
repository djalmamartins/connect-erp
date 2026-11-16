-- Connect Talk no alternador de ambientes do ERP, com o mesmo login (ADR 0323).
-- Permissão talk.access: quem a tem vê "Talk" no alternador e entra no Talk sem digitar senha de novo.
-- Nasce dada aos papéis que já atendem chamados (support.manage). Idempotente.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Atendimento por WhatsApp (Connect Talk)','talk.access','Atendimento','Entrar no Connect Talk pelo alternador de ambientes do ERP, com o mesmo login');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT rp.role_id, t.id FROM `access_role_permissions` rp
JOIN `access_permissions` s ON s.id = rp.permission_id AND s.slug = 'support.manage'
JOIN `access_permissions` t ON t.slug = 'talk.access';
