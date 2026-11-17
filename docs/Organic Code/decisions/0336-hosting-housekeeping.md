# 0336 — Faxina da hospedagem: logs por 6 meses, disco e certificado HTTPS

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.6 ("Rotação dos logs, disco e uso de memória; aviso de certificado HTTPS"); Djalma,
  16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0325 (cron), ADR 0333 (vigia), ADR 0335 (PDFs de vistoria)

## Contexto

O AppLogger grava um arquivo por dia em `storage/logs/movesos-AAAA-MM-DD.log` e nada os apagava. O plano tem 100 GB e 2
milhões de inodes. O certificado HTTPS é renovado pela Hostinger, mas se a renovação falhar o site para sem aviso.

## Decisão

1. `tools/hosting/housekeeping.php` no `cron.sh dia`: compacta (.gz) os logs diários com 2 dias ou mais e apaga os com mais
   de 183 dias. A data vem do nome do arquivo. Seis meses porque o Marco Civil da Internet (art. 15) pede guardar os
   registros de acesso a aplicação por esse prazo; menos que isso descumpre, mais que isso guarda dado pessoal sem
   necessidade (LGPD). Os `cron-*.log` já se limitam sozinhos (ADR 0325).
2. A mesma faxina mede o espaço da instalação (`du -sk`) e grava em `storage/temp/disk-usage`.
3. O vigia (ADR 0333) passa a avisar quando o espaço passa de 85% de `HOSTING_DISK_LIMIT_GB` (100) e quando o certificado
   HTTPS do `APP_URL` vence em menos de 15 dias (ou já venceu). O certificado não é conferido em `.lab`.

## Consequências

- Memória do PHP fica para medir no piloto (o `memory_limit` é do plano, não do sistema).
- Aviso do certificado do banco (Inter) perto de vencer continua pendente: depende de onde o certificado ficar guardado
  na produção.
