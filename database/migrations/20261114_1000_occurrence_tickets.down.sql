-- Reversão do ADR 0305: tira das ocorrências os chamados criados pela migration (protocolo OC...) e apaga esses chamados
-- (mensagens, eventos e anexos saem em cascata ou aqui). Chamados abertos depois pelo TicketService (CH...) ficam.
UPDATE erp_occurrences o
JOIN studio_support_tickets t ON t.id = o.ticket_id
SET o.ticket_id = NULL
WHERE t.protocol LIKE 'OC%';

DELETE e FROM studio_support_ticket_events e JOIN studio_support_tickets t ON t.id = e.ticket_id WHERE t.protocol LIKE 'OC%';
DELETE FROM studio_support_tickets WHERE protocol LIKE 'OC%';
