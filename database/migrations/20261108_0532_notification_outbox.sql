-- Outbox de notificações do ERP (ADR 0054): eventos que pedem aviso (ex.: chamada da lista de espera)
-- são gravados na mesma transação da ação e ficam pendentes até a fila de envio (container send,
-- próximo ciclo) consumir. Nada é enviado por esta tabela. event_key único torna o registro idempotente.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_notification_outbox` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `event_type` varchar(60) NOT NULL COMMENT 'Ex.: waitlist.called',
  `event_key` varchar(120) NOT NULL COMMENT 'Identifica o evento de origem (idempotência)',
  `subject_table` varchar(60) NOT NULL,
  `subject_id` int unsigned NOT NULL,
  `payload_json` text NOT NULL COMMENT 'Dados para o modelo do aviso, sem segredos',
  `status` enum('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `attempts` smallint unsigned NOT NULL DEFAULT 0,
  `available_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Próxima tentativa',
  `processed_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_notification_outbox_event` (`event_key`),
  KEY `idx_erp_notification_outbox_queue` (`status`, `available_at`),
  KEY `idx_erp_notification_outbox_subject` (`subject_table`, `subject_id`),
  CONSTRAINT `fk_erp_notification_outbox_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_notification_outbox_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
