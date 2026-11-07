ALTER TABLE `settings`
    ADD COLUMN IF NOT EXISTS `access_site` TINYINT(1) NOT NULL DEFAULT 1 AFTER `access_app`,
    ADD COLUMN IF NOT EXISTS `access_support` TINYINT(1) NOT NULL DEFAULT 1 AFTER `access_site`;

UPDATE `settings`
SET `access_site` = COALESCE(`access_site`, 1),
    `access_support` = COALESCE(`access_support`, 1);
