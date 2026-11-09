-- Disco Virtual (AP-122, ADR 0047): arquivos do condomínio por pasta, guardados fora da pasta
-- pública (storage/private/documents), com nome gerado pelo sistema, tipo conferido pelo conteúdo e
-- tamanho limitado. Download só com permissão e escopo. Exclusão lógica: o arquivo continua guardado.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `folder` enum('atas','contratos','plantas','notas_fiscais','outros') NOT NULL,
  `title` varchar(160) NOT NULL,
  `original_name` varchar(160) NOT NULL COMMENT 'Nome enviado, já limpo; nunca usado no disco',
  `path` varchar(120) NOT NULL COMMENT 'Relativo a storage/private/documents',
  `mime` varchar(120) NOT NULL,
  `size_bytes` int unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_documents_path` (`path`),
  KEY `idx_erp_documents_list` (`condominium_id`, `folder`, `deleted_at`, `created_at`),
  KEY `idx_erp_documents_hash` (`condominium_id`, `sha256`),
  CONSTRAINT `fk_erp_documents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_documents_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar Disco Virtual','erp.documents.view','ERP Documentos','Listar e baixar os arquivos dos condomínios do seu escopo'),
('Gerenciar Disco Virtual','erp.documents.manage','ERP Documentos','Enviar e excluir arquivos do Disco Virtual');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.documents.view','erp.documents.manage');
