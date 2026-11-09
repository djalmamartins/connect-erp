-- Importação de leituras (AP-044, extensão do ADR 0058): novo tipo em erp_imports. As leituras são
-- gravadas pelo MeterReadings (ADR 0045), com as mesmas regras do lançamento manual.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_imports`
  MODIFY `kind` enum('ofx','entries','unit_entries','payables','fractions','delinquents','readings') NOT NULL;
