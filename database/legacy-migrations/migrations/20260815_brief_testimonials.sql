CREATE TABLE IF NOT EXISTS `brief` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `townhouse` varchar(180) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_brief_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
