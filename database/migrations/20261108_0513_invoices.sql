-- Faturas (AP-032, ADR 0033): a cobrança de um tipo de fatura (0501) a todas as unidades (0401) de
-- um condomínio numa competência. Rascunho → emitida (cada item vira uma conta a receber) →
-- cancelada. Uma fatura ativa por condomínio, tipo e competência: active_key é 1 enquanto não
-- cancelada e NULL depois, e a chave única ignora NULL (cancelar libera para preparar de novo).
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_invoices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `invoice_type_id` int unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `due_on` date NOT NULL,
  `status` enum('draft','issued','cancelled') NOT NULL DEFAULT 'draft',
  `active_key` tinyint unsigned GENERATED ALWAYS AS (CASE WHEN `status` <> 'cancelled' THEN 1 END) STORED,
  `units_count` int unsigned NOT NULL DEFAULT 0,
  `amount_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Soma dos itens',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `issued_by` int unsigned DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_invoices_active` (`condominium_id`, `invoice_type_id`, `competence`, `active_key`),
  KEY `idx_erp_invoices_list` (`condominium_id`, `competence`, `status`),
  CONSTRAINT `fk_erp_invoices_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_invoices_type` FOREIGN KEY (`invoice_type_id`) REFERENCES `erp_invoice_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_invoices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoices_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoices_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um item por unidade; o valor pode ser ajustado enquanto a fatura é rascunho.
CREATE TABLE IF NOT EXISTS `erp_invoice_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `unit_label` varchar(40) NOT NULL COMMENT 'Rótulo da unidade na preparação',
  `amount_cents` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_invoice_items_unit` (`invoice_id`, `unit_id`),
  KEY `idx_erp_invoice_items_unit` (`unit_id`),
  CONSTRAINT `fk_erp_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `erp_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_invoice_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
