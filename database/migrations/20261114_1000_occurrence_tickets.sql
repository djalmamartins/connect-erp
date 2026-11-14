-- Toda ocorrência é um chamado (ADR 0305). As ocorrências antigas, sem chamado, ganham o seu na fila do Help Desk, com
-- os dados da ocorrência (condomínio, unidade, assunto, descrição, prioridade) e a situação equivalente:
-- aberta = open, em andamento = in_progress, resolvida = closed (assim as resolvidas não entram na fila de quem atende).
-- O protocolo dos chamados criados aqui é OC + o número da ocorrência com 10 dígitos (12 caracteres, como os CH...),
-- sem risco de colidir com os gerados pelo TicketService. Origem residents quando quem registrou é morador da unidade.
-- Nenhum aviso é enviado. Idempotente: só entram ocorrências sem chamado (a suíte reaplica as migrations).
INSERT INTO studio_support_tickets
  (protocol, subject, message, area, origin, condominium_id, unit_id, category, priority, status, requester_id, requester_name,
   created_by, due_at, resolved_at, created_at)
SELECT
  CONCAT('OC', LPAD(o.id, 10, '0')),
  LEFT(IF(CHAR_LENGTH(TRIM(o.subject)) >= 4, TRIM(o.subject), CONCAT('Ocorrência: ', TRIM(o.subject))), 180),
  CONCAT(o.description, CHAR(10), CHAR(10), '(Ocorrência #', o.id, ' de ', o.occurred_on, ')'),
  'general',
  IF(EXISTS (SELECT 1 FROM erp_residents r WHERE r.user_id = o.created_by AND r.unit_id = o.unit_id), 'residents', 'erp'),
  o.condominium_id, o.unit_id, 'Ocorrência',
  CASE o.priority WHEN 'baixa' THEN 'low' WHEN 'alta' THEN 'high' ELSE 'medium' END,
  CASE o.status WHEN 'active' THEN 'open' WHEN 'running' THEN 'in_progress' ELSE 'closed' END,
  o.created_by,
  IF(o.created_by IS NULL, 'Ocorrência do condomínio', NULL),
  o.created_by,
  o.created_at + INTERVAL (CASE o.priority WHEN 'baixa' THEN 120 WHEN 'alta' THEN 48 ELSE 72 END) HOUR,
  IF(o.status = 'done', o.updated_at, NULL),
  o.created_at
FROM erp_occurrences o
WHERE o.ticket_id IS NULL AND o.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM studio_support_tickets t WHERE t.protocol = CONCAT('OC', LPAD(o.id, 10, '0')));

INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, new_value)
SELECT t.id, NULL, 'origin', t.origin
FROM studio_support_tickets t
JOIN erp_occurrences o ON t.protocol = CONCAT('OC', LPAD(o.id, 10, '0'))
WHERE o.ticket_id IS NULL AND o.deleted_at IS NULL;

UPDATE erp_occurrences o
JOIN studio_support_tickets t ON t.protocol = CONCAT('OC', LPAD(o.id, 10, '0'))
SET o.ticket_id = t.id
WHERE o.ticket_id IS NULL AND o.deleted_at IS NULL;
