-- Endurecimento (ADR 0087): índices que faltavam para os filtros dos relatórios e da trava de cobrança.
-- Idempotente (a suíte reaplica as migrations a cada execução).

-- M3: avisos de boleto enviados (AP-099), trava de 7 dias do aviso de cobrança e lista da fila.
ALTER TABLE `erp_notification_outbox`
  ADD INDEX IF NOT EXISTS `idx_erp_notification_outbox_report` (`condominium_id`, `event_type`, `created_at`);

-- B3: relatórios de reservas (AP-085) e de ocorrências (AP-090) filtram por condomínio e período, sem situação.
ALTER TABLE `erp_reservations`
  ADD INDEX IF NOT EXISTS `idx_erp_reservations_period` (`condominium_id`, `reserved_on`);
ALTER TABLE `erp_occurrences`
  ADD INDEX IF NOT EXISTS `idx_erp_occurrences_period` (`condominium_id`, `occurred_on`);
