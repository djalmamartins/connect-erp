ALTER TABLE `settings`
    ADD COLUMN IF NOT EXISTS `view_support` VARCHAR(255) NULL DEFAULT 'support' AFTER `view_theme`;

UPDATE `settings` SET `view_support`='support' WHERE `view_support` IS NULL OR `view_support`='';

ALTER TABLE `movesos_versions`
    MODIFY `product` ENUM('web','app','studio','erp','support') NOT NULL DEFAULT 'studio';

INSERT INTO `movesos_versions` (`product`,`version`,`name`,`notes`,`status`,`created_by`,`published_at`)
SELECT 'support','1.0.0','Fundação','Versão inicial da Central de Suporte do MovesOS.','current',NULL,NOW()
WHERE NOT EXISTS (SELECT 1 FROM `movesos_versions` WHERE `product`='support');
