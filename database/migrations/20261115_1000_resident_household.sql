-- Aplicativo do morador, revisão de 15/11, etapa 4 (ADR 0302): a matriz do portal ganha "household", em que o
-- proprietário cadastra inquilino e morador e bloqueia inquilino. Começa desligada (linha ausente = desligado).
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_resident_portal_permissions`
  MODIFY COLUMN `feature` enum('boletos','reservations','occurrences','announcements','documents','accountability','deliveries','visitors','assemblies','household') NOT NULL;
