-- Chamados com contexto do condomínio (ADR 0034): todo chamado, venha de onde vier (Help Desk,
-- ERP, site, portal dos moradores ou API), cai na mesma fila do Help Desk com condomínio,
-- unidade, origem e telefone do solicitante. Colunas opcionais: chamados antigos e os do site
-- ficam sem condomínio. condominium_id é a unidade de segregação (ADR-002).
ALTER TABLE studio_support_tickets
    ADD COLUMN IF NOT EXISTS origin ENUM('helpdesk','erp','site','residents','api') NOT NULL DEFAULT 'helpdesk' AFTER area,
    ADD COLUMN IF NOT EXISTS condominium_id INT UNSIGNED NULL AFTER origin,
    ADD COLUMN IF NOT EXISTS unit_id INT UNSIGNED NULL AFTER condominium_id,
    ADD COLUMN IF NOT EXISTS requester_phone VARCHAR(30) NULL AFTER requester_email,
    ADD INDEX IF NOT EXISTS idx_support_ticket_condominium (condominium_id, status, created_at),
    ADD INDEX IF NOT EXISTS idx_support_ticket_unit (unit_id),
    ADD INDEX IF NOT EXISTS idx_support_ticket_origin (origin, created_at);

ALTER TABLE studio_support_tickets
    ADD CONSTRAINT fk_support_ticket_condominium FOREIGN KEY IF NOT EXISTS (condominium_id) REFERENCES condominiums (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_support_ticket_unit FOREIGN KEY IF NOT EXISTS (unit_id) REFERENCES erp_units (id) ON DELETE SET NULL;
