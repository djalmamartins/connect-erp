-- Cadastra slugs já usados no código que dependiam do fallback por nível (issue #8),
-- para que negar permissões não cadastradas não tire acesso de ninguém.
-- proposals.manage: mesma definição de components/proposals/database/001;
-- INSERT IGNORE mantém o registro se o módulo já o criou.
-- Os dois são concedidos só aos perfis que o fallback já liberava (nível legado >= 5);
-- o módulo de propostas também concede proposals.manage ao manager quando instalado.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Gerenciar propostas','proposals.manage','Comercial','Consultar e acompanhar propostas recebidas pelo site'),
('Acessar site','site.access','Sistema','Exibir o atalho do site institucional no Studio');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin','client_admin') AND p.slug = 'proposals.manage';

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin','client_admin') AND p.slug = 'site.access';
