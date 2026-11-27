-- Volta dos papéis simplificados (ADR 0682). Recria o "Administrador do cliente" com o conjunto antigo,
-- mas as contas que foram promovidas a Administrador global continuam nele (não há como saber quem
-- eram sem a auditoria; a auditoria do Studio guarda quem mudou de papel). Quem estava em Financeiro ou
-- Atendente volta para Operador antes de os dois papéis saírem.
INSERT INTO `access_roles` (`name`,`slug`,`level`,`description`) VALUES
('Administrador do cliente','client_admin',70,'Administração completa da própria operação')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `level` = VALUES(`level`), `description` = VALUES(`description`);
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug <> 'system.manage' AND p.slug NOT LIKE 'erp.%' WHERE r.slug = 'client_admin';

UPDATE `access_user_roles` ur JOIN `access_roles` f ON f.id = ur.role_id AND f.slug IN ('financial','attendant')
JOIN `access_roles` o ON o.slug = 'operator'
SET ur.role_id = o.id;
DELETE FROM `access_roles` WHERE `slug` IN ('financial','attendant');

UPDATE `access_roles` SET `name` = 'Super administrador', `description` = 'Administração global da plataforma' WHERE `slug` = 'super_admin';
UPDATE `access_roles` SET `name` = 'Desenvolvedor', `description` = 'Controle técnico integral do MovesOS' WHERE `slug` = 'developer';
UPDATE `access_roles` SET `name` = 'Gerente', `description` = 'Gestão de conteúdo, equipe e relatórios' WHERE `slug` = 'manager';
UPDATE `access_roles` SET `name` = 'Operador', `description` = 'Execução das rotinas autorizadas' WHERE `slug` = 'operator';
UPDATE `access_roles` SET `name` = 'Usuário', `description` = 'Acesso aos próprios recursos' WHERE `slug` = 'user';
UPDATE `access_roles` SET `name` = 'Imobiliária parceira', `description` = 'Somente leitura de unidades e moradores, com contato mascarado, nos condomínios concedidos' WHERE `slug` = 'erp_partner';

DELETE rp FROM `access_role_permissions` rp JOIN `access_roles` r ON r.id = rp.role_id WHERE r.slug IN ('manager','operator');
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug IN ('studio.access','dashboard.view','pages.manage','articles.manage','media.manage','slides.manage','faqs.manage','notifications.manage','audit.view','reports.view','erp.access','app.access','support.manage','talk.access')
WHERE r.slug = 'manager';
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug IN ('studio.access','dashboard.view','articles.manage','media.manage','erp.access','app.access')
WHERE r.slug = 'operator';
