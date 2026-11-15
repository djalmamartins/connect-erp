-- Boas-vindas e foto do morador no aplicativo (revisão de 15/11, ADR 0302). Uma linha por conta (user_id), porque a foto
-- e o primeiro acesso são da pessoa, não do vínculo com a unidade. A foto fica fora da pasta pública
-- (storage/private/resident-photos) e photo_path guarda só o caminho relativo dela. Idempotente.
CREATE TABLE IF NOT EXISTS `erp_resident_app_profiles` (
  `user_id` int unsigned NOT NULL,
  `photo_path` varchar(120) DEFAULT NULL COMMENT 'Relativo a storage/private/resident-photos; NULL = sem foto',
  `onboarded_at` datetime DEFAULT NULL COMMENT 'Quando concluiu (ou pulou) as boas-vindas; NULL = mostrar no próximo acesso',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
