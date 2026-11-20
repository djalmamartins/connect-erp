-- E-mails do morador limitados (ADR 0601): marca de cada boleto já avisado, para o mesmo aviso nunca sair duas vezes.
-- item_type/item_id: o boleto (erp_bank_charges) ou, sem boleto, a conta a receber (erp_entries).
-- milestone: 0 = aviso do dia do vencimento; 10, 15, 30, 45, 60 = dias de atraso.
-- Sem FK: é só controle de envio e não trava a exclusão do condomínio ou da conta.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_resident_charge_notices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `item_type` enum('charge','entry') NOT NULL,
  `item_id` int unsigned NOT NULL,
  `milestone` smallint unsigned NOT NULL COMMENT '0 = vence hoje; senão, dias de atraso',
  `notified_on` date NOT NULL,
  `event_key` varchar(120) NOT NULL COMMENT 'Aviso da erp_notification_outbox que levou este boleto',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_charge_notices_item` (`item_type`, `item_id`, `milestone`),
  KEY `idx_erp_resident_charge_notices_unit` (`condominium_id`, `unit_id`, `notified_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
