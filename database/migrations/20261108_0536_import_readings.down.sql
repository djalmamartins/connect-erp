-- Reverte 20261108_0536: as importações de leituras saem do registro (as leituras gravadas ficam).
DELETE FROM `erp_imports` WHERE `kind` = 'readings';
ALTER TABLE `erp_imports`
  MODIFY `kind` enum('ofx','entries','unit_entries','payables','fractions','delinquents') NOT NULL;
