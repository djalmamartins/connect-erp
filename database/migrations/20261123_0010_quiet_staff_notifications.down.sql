-- Reverte 20261123_0010_quiet_staff_notifications (ADR 0645): nada a desfazer. Os avisos marcados como lidos
-- continuam guardados e os e-mails cancelados ficam no histórico da fila; o código anterior volta a gerar avisos novos.
SELECT 1;
