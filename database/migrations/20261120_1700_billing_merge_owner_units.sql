-- Boleto único por responsável (ADR 0399, pedido do Djalma em 20/11/2026): chave na regra de cobrança do
-- condomínio (ou no padrão da Connect). Ligada, o boleto da fatura junta as contas da mesma fatura das outras
-- unidades cujo responsável pelo pagamento tem o mesmo CPF/CNPJ. Padrão desligado. Idempotente.
ALTER TABLE `erp_billing_rules`
  ADD COLUMN IF NOT EXISTS `merge_owner_units` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT 'Juntar no boleto as unidades do mesmo responsável (ADR 0399)' AFTER `breach_open_installments`;
