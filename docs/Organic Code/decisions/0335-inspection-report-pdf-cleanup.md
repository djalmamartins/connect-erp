# 0335 — Relatório de vistoria: PDF só enquanto o e-mail precisa dele

- Status: Aceito
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.5 ("O relatório de vistoria pode passar a ser gerado só para o envio"); Djalma, 16/11/2026:
  quer o sistema gerando os PDFs na hora em vez de guardá-los
- Relacionadas: ADR 0325 (backup), ADR 0333 (cron)

## Contexto

O relatório de vistoria já é gerado na hora (Dompdf) quando alguém envia por e-mail, mas o arquivo ficava para sempre em
`storage/private/inspections/reports/<condomínio>/`, porque a fila de e-mail (`mail_queue`) lê o anexo do disco no envio e nas
novas tentativas. A tela e a API já geram o PDF na hora para baixar; ninguém lê esses arquivos depois do envio.

## Decisão

1. `InspectionReportCleanup` apaga, uma vez por dia (`cron.sh dia`, `tools/inspection-reports-cleanup.php`), os `.pdf` de
   `reports/` com mais de 24 horas que não estão em nenhum e-mail pendente, em nova tentativa ou em envio.
2. Só mexe em `.pdf` dentro de `reports/`; as fotos da vistoria (que não dá para gerar de novo) nunca são tocadas.
3. Reenviar o relatório gera um PDF novo, com os dados do momento.

## Consequências

- O disco e o backup deixam de crescer com cópias do relatório. Fotos, notas e anexos continuam no backup.
- Um e-mail que falhou de vez (`failed`) perde o anexo depois de 24 horas: para mandar de novo, use "Enviar relatório" na tela.
- `--dry-run` mostra quantos seriam apagados sem apagar.
