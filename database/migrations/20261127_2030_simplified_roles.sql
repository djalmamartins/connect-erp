-- Papéis simplificados (ADR 0682, pedido do Djalma em 27/11/2026): Administrador global, Gerente,
-- Financeiro, Operador e Atendente para a equipe; Morador e Imobiliária fora dela. O Desenvolvedor
-- continua, só para o suporte técnico, e só o próprio desenvolvedor o vê nas listas.
-- Idempotente. Não apaga contas: quem era "Administrador do cliente" vira Administrador global
-- (ganha acesso, não perde), e só então o papel antigo sai.

-- 1. Nomes, níveis e descrições.
UPDATE `access_roles` SET `name` = 'Desenvolvedor', `level` = 100, `description` = 'Suporte técnico da plataforma, só a equipe técnica vê este perfil' WHERE `slug` = 'developer';
UPDATE `access_roles` SET `name` = 'Administrador global', `level` = 90, `description` = 'Tudo no ERP, no Studio e no Help Desk, em todos os condomínios' WHERE `slug` = 'super_admin';
UPDATE `access_roles` SET `name` = 'Gerente', `level` = 50, `description` = 'Operação e financeiro dos condomínios ligados, não mexe em usuários, permissões nem configurações' WHERE `slug` = 'manager';
UPDATE `access_roles` SET `name` = 'Operador', `level` = 30, `description` = 'Cadastros e rotina dos condomínios ligados, chamados e vistorias, sem financeiro' WHERE `slug` = 'operator';
UPDATE `access_roles` SET `name` = 'Morador', `level` = 10, `description` = 'Proprietário ou inquilino: só o app do morador' WHERE `slug` = 'user';
UPDATE `access_roles` SET `name` = 'Imobiliária', `level` = 1, `description` = 'Somente leitura das unidades dos condomínios ligados, com contato mascarado' WHERE `slug` = 'erp_partner';
INSERT INTO `access_roles` (`name`,`slug`,`level`,`description`) VALUES
('Financeiro','financial',40,'Lançamentos, faturas, boletos, cobrança, acordos e fechamento dos condomínios ligados, sem cadastros de rotina'),
('Atendente','attendant',20,'Chamados do SAC e atendimento aos moradores dos condomínios ligados, consulta boletos sem alterar')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `level` = VALUES(`level`), `description` = VALUES(`description`);

-- 2. Administrador do cliente -> Administrador global, sem perder nada do que o papel antigo dava.
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT s.id, rp.permission_id FROM `access_roles` s
JOIN `access_roles` c ON c.slug = 'client_admin'
JOIN `access_role_permissions` rp ON rp.role_id = c.id
WHERE s.slug = 'super_admin';
UPDATE `access_user_roles` ur JOIN `access_roles` c ON c.id = ur.role_id AND c.slug = 'client_admin'
JOIN `access_roles` s ON s.slug = 'super_admin'
SET ur.role_id = s.id;
UPDATE `users` u JOIN `access_user_roles` ur ON ur.user_id = u.id JOIN `access_roles` r ON r.id = ur.role_id AND r.slug = 'super_admin'
SET u.level = 10 WHERE u.level < 10;
DELETE FROM `access_roles` WHERE `slug` = 'client_admin' AND NOT EXISTS (SELECT 1 FROM `access_user_roles` ur WHERE ur.role_id = `access_roles`.id);

-- 3. Administrador global e Desenvolvedor: todas as permissões cadastradas.
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r CROSS JOIN `access_permissions` p WHERE r.slug IN ('developer','super_admin');

-- 4. Gerente, Financeiro, Operador e Atendente: o conjunto exato de cada um (o que sobrava sai).
DELETE rp FROM `access_role_permissions` rp JOIN `access_roles` r ON r.id = rp.role_id WHERE r.slug IN ('manager','financial','operator','attendant');

-- Gerente (126 permissões).
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug IN (
  'app.access','articles.manage','audit.view','dashboard.view','erp.access','erp.access_admin.view',
  'erp.access_logs.view','erp.agreements.manage','erp.agreements.view','erp.announcements.manage',
  'erp.announcements.view','erp.assemblies.manage','erp.assemblies.view','erp.assets.manage','erp.assets.view',
  'erp.audit.view','erp.bank_accounts.manage','erp.bank_accounts.view','erp.bank_charges.manage',
  'erp.bank_charges.view','erp.billing_rules.manage','erp.billing_rules.view','erp.board.manage',
  'erp.board.view','erp.chart.manage','erp.chart.view','erp.check_copies.manage','erp.check_copies.view',
  'erp.closing.manage','erp.closing.view','erp.collections.manage','erp.collections.view',
  'erp.commitments.manage','erp.commitments.view','erp.common_areas.manage','erp.common_areas.view',
  'erp.condominiums.manage','erp.condominiums.view','erp.contacts.manage','erp.contacts.view',
  'erp.contracts.manage','erp.contracts.view','erp.cost_centers.manage','erp.cost_centers.view',
  'erp.deliveries.manage','erp.deliveries.view','erp.delivery_notices.manage','erp.delivery_notices.view',
  'erp.documents.manage','erp.documents.view','erp.employees.manage','erp.employees.view','erp.entries.manage',
  'erp.entries.view','erp.gatehouses.manage','erp.gatehouses.view','erp.imports.manage','erp.imports.view',
  'erp.inspections.manage','erp.inspections.perform','erp.inspections.view','erp.insurances.manage',
  'erp.insurances.view','erp.invoice_types.manage','erp.invoice_types.view','erp.invoices.manage',
  'erp.invoices.view','erp.lawyers.manage','erp.lawyers.view','erp.linkable_areas.manage',
  'erp.linkable_areas.view','erp.lost_found.manage','erp.lost_found.view','erp.maintenances.manage',
  'erp.maintenances.view','erp.management_fees.view','erp.meter_readings.manage','erp.meter_readings.view',
  'erp.meters.manage','erp.meters.view','erp.moves.manage','erp.moves.view','erp.myday.team',
  'erp.notifications.manage','erp.notifications.view','erp.occurrences.manage','erp.occurrences.view',
  'erp.onboarding.catalog','erp.permissions.view','erp.pets.manage','erp.pets.view','erp.pouches.manage',
  'erp.pouches.view','erp.recurring_entries.manage','erp.recurring_entries.view','erp.reservations.manage',
  'erp.reservations.view','erp.residents.manage','erp.residents.view','erp.settings.view',
  'erp.suppliers.manage','erp.suppliers.view','erp.tariffs.manage','erp.tariffs.view',
  'erp.unit_documents.manage','erp.unit_documents.view','erp.units.manage','erp.units.view','erp.users.view',
  'erp.vehicles.manage','erp.vehicles.view','erp.visit_forecasts.manage','erp.visit_forecasts.view',
  'erp.visitors.manage','erp.visitors.view','faqs.manage','media.manage','notifications.manage','pages.manage',
  'reports.view','site.access','slides.manage','studio.access','support.manage','talk.access',
  'testimonials.manage')
WHERE r.slug = 'manager';

-- Financeiro (49 permissões).
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug IN (
  'app.access','erp.access','erp.agreements.manage','erp.agreements.view','erp.audit.view',
  'erp.bank_accounts.view','erp.bank_charges.manage','erp.bank_charges.view','erp.billing_rules.manage',
  'erp.billing_rules.view','erp.chart.manage','erp.chart.view','erp.check_copies.manage',
  'erp.check_copies.view','erp.closing.manage','erp.closing.view','erp.collections.manage',
  'erp.collections.view','erp.commitments.manage','erp.commitments.view','erp.condominiums.view',
  'erp.contacts.view','erp.contracts.view','erp.cost_centers.manage','erp.cost_centers.view',
  'erp.documents.view','erp.entries.manage','erp.entries.view','erp.imports.manage','erp.imports.view',
  'erp.invoice_types.manage','erp.invoice_types.view','erp.invoices.manage','erp.invoices.view',
  'erp.lawyers.view','erp.management_fees.view','erp.meter_readings.view','erp.meters.view',
  'erp.recurring_entries.manage','erp.recurring_entries.view','erp.residents.view','erp.suppliers.manage',
  'erp.suppliers.view','erp.tariffs.manage','erp.tariffs.view','erp.unit_documents.manage',
  'erp.unit_documents.view','erp.units.view','reports.view')
WHERE r.slug = 'financial';

-- Operador (66 permissões).
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug IN (
  'app.access','erp.access','erp.announcements.manage','erp.announcements.view','erp.assemblies.manage',
  'erp.assemblies.view','erp.assets.manage','erp.assets.view','erp.board.manage','erp.board.view',
  'erp.common_areas.manage','erp.common_areas.view','erp.condominiums.view','erp.contacts.manage',
  'erp.contacts.view','erp.contracts.view','erp.deliveries.manage','erp.deliveries.view',
  'erp.delivery_notices.manage','erp.delivery_notices.view','erp.documents.manage','erp.documents.view',
  'erp.employees.manage','erp.employees.view','erp.gatehouses.manage','erp.gatehouses.view',
  'erp.inspections.perform','erp.inspections.view','erp.insurances.view','erp.lawyers.view',
  'erp.linkable_areas.manage','erp.linkable_areas.view','erp.lost_found.manage','erp.lost_found.view',
  'erp.maintenances.manage','erp.maintenances.view','erp.meter_readings.manage','erp.meter_readings.view',
  'erp.meters.manage','erp.meters.view','erp.moves.manage','erp.moves.view','erp.myday.team',
  'erp.notifications.view','erp.occurrences.manage','erp.occurrences.view','erp.pets.manage','erp.pets.view',
  'erp.pouches.manage','erp.pouches.view','erp.reservations.manage','erp.reservations.view',
  'erp.residents.manage','erp.residents.view','erp.suppliers.view','erp.unit_documents.view','erp.units.manage',
  'erp.units.view','erp.vehicles.manage','erp.vehicles.view','erp.visit_forecasts.manage',
  'erp.visit_forecasts.view','erp.visitors.manage','erp.visitors.view','support.manage','talk.access')
WHERE r.slug = 'operator';

-- Atendente (34 permissões).
INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug IN (
  'app.access','erp.access','erp.agreements.view','erp.announcements.view','erp.assemblies.view',
  'erp.bank_charges.view','erp.common_areas.view','erp.condominiums.view','erp.contacts.view',
  'erp.deliveries.manage','erp.deliveries.view','erp.delivery_notices.view','erp.documents.view',
  'erp.invoices.view','erp.lost_found.manage','erp.lost_found.view','erp.maintenances.view','erp.moves.view',
  'erp.occurrences.manage','erp.occurrences.view','erp.pets.view','erp.pouches.view','erp.reservations.manage',
  'erp.reservations.view','erp.residents.view','erp.unit_documents.view','erp.units.view','erp.vehicles.view',
  'erp.visit_forecasts.manage','erp.visit_forecasts.view','erp.visitors.manage','erp.visitors.view',
  'support.manage','talk.access')
WHERE r.slug = 'attendant';

-- 5. Nível legado coerente com o papel (o Studio usa users.level para o fallback antigo).
UPDATE `users` u JOIN `access_user_roles` ur ON ur.user_id = u.id JOIN `access_roles` r ON r.id = ur.role_id
SET u.level = CASE WHEN r.slug IN ('developer','super_admin') THEN 10 WHEN r.slug IN ('manager','financial','operator','attendant') THEN 2 ELSE 1 END;
