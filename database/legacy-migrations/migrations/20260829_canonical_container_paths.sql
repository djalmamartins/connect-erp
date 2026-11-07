-- Canonicalize persisted theme identifiers after the physical container migration.
UPDATE `settings`
SET
    `view_theme` = CASE `view_theme` WHEN 'connect_by_moves' THEN 'default' ELSE `view_theme` END,
    `view_support` = CASE `view_support` WHEN 'support_by_moves' THEN 'support' ELSE `view_support` END,
    `view_app` = CASE `view_app` WHEN 'app_connect' THEN 'default' ELSE `view_app` END,
    `view_erp` = CASE `view_erp` WHEN 'connect' THEN 'default' ELSE `view_erp` END,
    `view_admin` = CASE `view_admin` WHEN 'moves_studio' THEN 'default' ELSE `view_admin` END,
    `view_mail` = CASE `view_mail` WHEN 'mail' THEN 'default' ELSE `view_mail` END;
