-- Permissões do ERP e de segurança sobre o núcleo access_* existente.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Acessar ERP','erp.access','ERP','Entrar no ERP da Connect'),
('Visualizar condomínios','erp.condominiums.view','ERP','Consultar os condomínios vinculados'),
('Gerenciar condomínios','erp.condominiums.manage','ERP','Cadastrar e alterar condomínios vinculados'),
('Todos os condomínios','erp.condominiums.all','ERP','Acessar todos os condomínios da Connect sem vínculo individual'),
('Gerenciar 2FA de usuários','security.2fa.manage','Segurança','Redefinir a autenticação em dois fatores de outros usuários');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin')
  AND p.slug IN ('erp.access','erp.condominiums.view','erp.condominiums.manage','erp.condominiums.all','security.2fa.manage');
