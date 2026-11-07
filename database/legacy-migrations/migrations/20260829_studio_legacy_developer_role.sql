-- Compatibilidade da hierarquia legada: nível 10 representa desenvolvedor.
INSERT INTO `access_permissions` (`name`, `slug`, `group_name`, `description`)
VALUES ('Visualizar logs do sistema', 'logs.view', 'Desenvolvimento', 'Acesso técnico aos erros, exceções e incidentes do MovesOS')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `group_name`=VALUES(`group_name`), `description`=VALUES(`description`);

INSERT IGNORE INTO `access_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `access_roles` r CROSS JOIN `access_permissions` p
WHERE r.slug IN ('developer', 'super_admin') AND p.slug = 'logs.view';

INSERT INTO `access_user_roles` (`user_id`, `role_id`, `assigned_by`)
SELECT u.id, r.id, NULL
FROM `users` u
JOIN `access_roles` r ON r.slug = 'developer'
LEFT JOIN `access_user_roles` ur ON ur.user_id = u.id
WHERE u.level >= 10 AND u.status <> 'trash' AND ur.user_id IS NULL;
