-- Sininho da equipe só com o básico (ADR 0645). Idempotente, não apaga nada.
-- Os avisos antigos de erro, de alteração no sistema e de artigo novo ficam marcados como lidos, para o contador
-- do sino voltar ao que importa. Os incidentes continuam em app_log e a auditoria em system_audit_logs.
UPDATE `notifications`
   SET `view` = 1, `read_at` = COALESCE(`read_at`, NOW())
 WHERE `view` = 0
   AND (`title` IN ('Erro detectado no MovesOS', 'Alteração no sistema') OR `title` LIKE 'Novo artigo: %');

-- E-mails "Erro detectado no MovesOS" que ainda estavam na fila não saem mais.
UPDATE `mail_queue`
   SET `status` = 'cancelled'
 WHERE `status` = 'pending' AND `source_log_id` IS NOT NULL;
