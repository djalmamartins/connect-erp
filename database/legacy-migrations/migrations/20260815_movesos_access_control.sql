CREATE TABLE IF NOT EXISTS `access_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `level` int NOT NULL DEFAULT 10,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_access_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `access_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `group_name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_access_permissions_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `access_role_permissions` (
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  CONSTRAINT `fk_access_rp_role` FOREIGN KEY (`role_id`) REFERENCES `access_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_access_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `access_permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `access_user_roles` (
  `user_id` int unsigned NOT NULL,
  `role_id` int unsigned NOT NULL,
  `assigned_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`), KEY `idx_access_user_role` (`role_id`),
  CONSTRAINT `fk_access_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_access_ur_role` FOREIGN KEY (`role_id`) REFERENCES `access_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `access_user_overrides` (
  `user_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  `effect` enum('allow','deny') NOT NULL,
  `assigned_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`permission_id`),
  CONSTRAINT `fk_access_uo_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_access_uo_permission` FOREIGN KEY (`permission_id`) REFERENCES `access_permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `access_roles` (`name`,`slug`,`level`,`description`) VALUES
('Desenvolvedor','developer',100,'Controle técnico integral do MovesOS'),
('Super administrador','super_admin',90,'Administração global da plataforma'),
('Administrador do cliente','client_admin',70,'Administração completa da própria operação'),
('Gerente','manager',50,'Gestão de conteúdo, equipe e relatórios'),
('Operador','operator',30,'Execução das rotinas autorizadas'),
('Usuário','user',10,'Acesso aos próprios recursos')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`level`=VALUES(`level`),`description`=VALUES(`description`);

INSERT INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Acessar Studio','studio.access','Sistema','Entrar no painel administrativo'),
('Visualizar dashboard','dashboard.view','Sistema','Consultar indicadores do painel'),
('Gerenciar páginas','pages.manage','Conteúdo','Criar e alterar páginas'),
('Gerenciar artigos','articles.manage','Conteúdo','Criar, editar e publicar artigos'),
('Gerenciar mídia','media.manage','Conteúdo','Consultar e enviar arquivos'),
('Gerenciar destaques','slides.manage','Conteúdo','Administrar slides e destaques'),
('Gerenciar perguntas','faqs.manage','Conteúdo','Administrar perguntas frequentes'),
('Enviar notificações','notifications.manage','Comunicação','Cadastrar e enviar mensagens'),
('Visualizar auditoria','audit.view','Segurança','Consultar alterações recentes'),
('Gerenciar usuários','users.manage','Segurança','Criar usuários e definir acessos'),
('Visualizar relatórios','reports.view','Gestão','Consultar relatórios'),
('Alterar configurações','settings.manage','Sistema','Editar configurações gerais'),
('Acessar ERP','erp.access','ERP','Entrar no ERP'),
('Acessar aplicativo','app.access','Aplicativo','Entrar no aplicativo do usuário'),
('Gerenciar sistema','system.manage','Sistema','Administrar recursos globais do MovesOS')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`);

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r CROSS JOIN access_permissions p WHERE r.slug IN ('developer','super_admin');
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r JOIN access_permissions p ON p.slug <> 'system.manage' WHERE r.slug='client_admin';
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r JOIN access_permissions p ON p.slug IN ('studio.access','dashboard.view','pages.manage','articles.manage','media.manage','slides.manage','faqs.manage','notifications.manage','audit.view','reports.view','erp.access','app.access') WHERE r.slug='manager';
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r JOIN access_permissions p ON p.slug IN ('studio.access','dashboard.view','articles.manage','media.manage','erp.access','app.access') WHERE r.slug='operator';
DELETE rp FROM access_role_permissions rp JOIN access_roles r ON r.id=rp.role_id JOIN access_permissions p ON p.id=rp.permission_id WHERE r.slug='user' AND p.slug='erp.access';
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r JOIN access_permissions p ON p.slug='app.access' WHERE r.slug='user';

INSERT IGNORE INTO access_user_roles (user_id,role_id)
SELECT u.id,r.id FROM users u JOIN access_roles r ON r.slug=CASE WHEN u.level>=10 THEN 'super_admin' WHEN u.level>=5 THEN 'client_admin' WHEN u.level>=2 THEN 'manager' ELSE 'user' END;

UPDATE access_user_roles SET role_id=(SELECT id FROM access_roles WHERE slug='developer') WHERE user_id=1;
UPDATE users u JOIN access_user_roles ur ON ur.user_id=u.id JOIN access_roles r ON r.id=ur.role_id
SET u.level=CASE WHEN r.slug IN ('developer','super_admin') THEN 10 WHEN r.slug='client_admin' THEN 5 WHEN r.slug IN ('manager','operator') THEN 2 ELSE 1 END;
