-- Aplicativo do morador (ADR 0302), em app.<domínio>. Idempotente (a suíte reaplica as migrations a cada execução).
-- 1. A matriz do portal (AP-153, ADR 0090) ganha prestação de contas, encomendas, visitantes e assembleias.
--    Continua tudo desligado por padrão: linha ausente = desligado.
ALTER TABLE `erp_resident_portal_permissions`
  MODIFY COLUMN `feature` enum('boletos','reservations','occurrences','announcements','documents','accountability','deliveries','visitors','assemblies') NOT NULL;

-- 2. Documentos do Disco Virtual que a administradora libera para os moradores do condomínio (convenção,
--    regimento, atas). Padrão 0: nenhum arquivo antigo aparece no aplicativo sem alguém marcar.
ALTER TABLE `erp_documents`
  ADD COLUMN IF NOT EXISTS `shared_with_residents` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Visível no aplicativo do morador (ADR 0302)' AFTER `scan_status`,
  ADD INDEX IF NOT EXISTS `idx_erp_documents_shared` (`condominium_id`, `shared_with_residents`, `deleted_at`);
