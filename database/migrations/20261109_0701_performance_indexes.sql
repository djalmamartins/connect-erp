-- Índices das consultas por data de pagamento (ADR 0064): prestação de contas por caixa
-- (FinancialReports::statement), saldos e conciliação por conta bancária (BankBalances,
-- FinancialReports, Imports::systemBalance e os candidatos da conciliação do OFX).
-- Só acrescenta índices: não muda dados. Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_entries`
  ADD INDEX IF NOT EXISTS `idx_erp_entries_paid` (`condominium_id`, `paid_on`),
  ADD INDEX IF NOT EXISTS `idx_erp_entries_bank_paid` (`bank_account_id`, `paid_on`);
