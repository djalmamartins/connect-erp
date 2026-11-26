-- Reverte 20261126_2300 (ADR 0660): tira a fila e os aparelhos dos apps da equipe e as sessões da equipe.
-- As sessões de morador continuam valendo; as da equipe são apagadas (todos precisam entrar de novo no app).
DROP TABLE IF EXISTS `team_push_queue`;
DROP TABLE IF EXISTS `team_push_devices`;
DELETE FROM `erp_resident_app_tokens` WHERE `audience` = 'team';
DELETE FROM `erp_resident_app_challenges` WHERE `audience` = 'team';
ALTER TABLE `erp_resident_app_tokens` DROP COLUMN IF EXISTS `audience`;
ALTER TABLE `erp_resident_app_challenges` DROP COLUMN IF EXISTS `audience`;
