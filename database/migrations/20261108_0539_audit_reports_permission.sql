-- Relatórios administrativos (ADR 0084): logs do ERP (AP-097) e pessoas com acesso (AP-098) ficam
-- atrás de uma permissão própria. Os logs não têm condomínio, então exigem também acesso a todos os
-- condomínios (CondominiumScope::hasGlobalAccess). Idempotente (a suíte reaplica as migrations).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar auditoria do ERP','erp.audit.view','ERP Relatórios','Consultar os logs do ERP e quem tem acesso a cada condomínio');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug = 'erp.audit.view';
