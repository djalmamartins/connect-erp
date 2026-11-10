-- Usuários do Sistema (AP-149) e Permissões de Usuários (AP-150), ADR 0168: permissões da matriz para as
-- duas telas. Fail-closed: só developer e super_admin recebem. Os outros papéis ganham pelo Studio ou pela
-- própria tela AP-150, por quem tem acesso a todos os condomínios. Sem tabela nova: reaproveita
-- access_roles, access_permissions, access_role_permissions, access_user_overrides e
-- user_condominium_access. Idempotente.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar usuários do ERP','erp.users.view','ERP Usuários','Consultar quem tem acesso aos condomínios do escopo'),
('Gerenciar usuários do ERP','erp.users.manage','ERP Usuários','Dar e tirar acesso a condomínios para contas que já existem'),
('Visualizar permissões do ERP','erp.permissions.view','ERP Usuários','Consultar papéis e permissões do ERP'),
('Gerenciar permissões do ERP','erp.permissions.manage','ERP Usuários','Alterar permissões do ERP de papéis e usuários');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.users.view','erp.users.manage','erp.permissions.view','erp.permissions.manage');
