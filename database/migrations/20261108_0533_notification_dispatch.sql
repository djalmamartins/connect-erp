-- Fila de envio das notificações do ERP (ADR 0055): como cada evento da outbox (0532) foi entregue
-- (modo log, sandbox ou production) e a trilha por destinatário, com o e-mail mascarado e em hash.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_notification_outbox`
  ADD COLUMN IF NOT EXISTS `delivery_mode` enum('log','sandbox','production') DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `recipients_count` smallint unsigned NOT NULL DEFAULT 0 AFTER `delivery_mode`;

CREATE TABLE IF NOT EXISTS `erp_notification_deliveries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `outbox_id` int unsigned NOT NULL,
  `delivery_mode` enum('log','sandbox','production') NOT NULL,
  `recipient_hash` char(64) NOT NULL COMMENT 'sha256 do e-mail do morador (sem o e-mail em claro)',
  `recipient_masked` varchar(120) NOT NULL COMMENT 'Ex.: a***@exemplo.com',
  `sent_to` varchar(190) DEFAULT NULL COMMENT 'Endereço efetivo: a caixa de teste no sandbox; vazio no modo log',
  `mail_queue_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_notification_deliveries` (`outbox_id`, `recipient_hash`),
  KEY `idx_erp_notification_deliveries_queue` (`mail_queue_id`),
  CONSTRAINT `fk_erp_notification_deliveries_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `erp_notification_outbox` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar notificações','erp.notifications.view','ERP Comunicação','Consultar os avisos da fila e como cada um foi entregue'),
('Gerenciar notificações','erp.notifications.manage','ERP Comunicação','Processar a fila e reenviar avisos que falharam');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.notifications.view','erp.notifications.manage');
