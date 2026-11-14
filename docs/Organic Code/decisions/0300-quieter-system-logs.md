<!-- markdownlint-disable MD024 -->
# 0300 — Menos registros repetidos no log e nos avisos do Studio

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** AppLogger (log do sistema, `/studio/system-logs`), `Source\Support\Audit` (trilha de auditoria),
  ADR 0055 (fila de avisos do ERP), ADR 0108 (posse do aviso na fila).

## Contexto

O Djalma relatou (14/11) muitos registros de "permissão negada" e de "Alteração no sistema — alterou o registro
erp_notification_outbox #615" no Studio. A análise da cópia do banco de 14/11 03:09 mostrou duas causas:

1. **Log do sistema (`app_log`).** O `AppLogger` só somava uma nova ocorrência ao incidente aberto quando ele tinha sido
   visto nos últimos 5 minutos. Fora disso abria outro registro. Entre 07 e 09/11 seis permissões ainda não estavam no
   banco (`erp.invoice_types.view`, `erp.notifications.view`, `erp.settings.view`, `proposals.manage`, `site.access`,
   `erp.inspections.view`), e cada tela que as consultava gerou dezenas de registros "Permissão não cadastrada negada",
   um a cada 5 minutos de uso. As migrations dessas permissões já rodaram e nenhum registro novo apareceu depois de 09/11,
   mas os antigos continuam "abertos" e somam centenas de ocorrências.
2. **Avisos do Studio (`notifications`).** `Audit::record` cria um aviso "Alteração no sistema" para o administrador
   principal sempre que quem alterou não é ele. Na fila de avisos do ERP quem altera é o agendador, sem usuário, então
   cada aviso processado (`erp_notification_outbox`) virava um aviso no sino do Studio, a cada minuto.

## Decisão

- O `AppLogger` passa a juntar o mesmo erro (mesma impressão digital) no incidente aberto visto nas últimas 24 horas,
  somando ocorrências e atualizando "visto por último". O alerta por e-mail continua só no primeiro registro do incidente.
- `Audit::record` continua gravando toda alteração em `system_audit_logs`, inclusive as das rotinas. Só deixa de criar o
  aviso "Alteração no sistema" quando não há usuário logado (agendador, fila, scripts). Alterações feitas por pessoas
  continuam avisando o administrador como antes.
- Nenhuma migration: os registros antigos já tratados podem ser marcados como resolvidos na própria página de logs
  (ação em lote "Resolver"), e os avisos antigos marcados como lidos no Studio.

## Consequências

- Menos linhas repetidas na página de logs e nenhum aviso no sino para cada e-mail da fila.
- Um erro que volta depois de mais de 24 horas abre um incidente novo (e um alerta novo), como antes acontecia depois de
  5 minutos.
- A trilha de auditoria não perde nada.

## Numeração

A faixa de threads avulsas 0140–0159 acabou e 0200–0299 é do Avançar (0258 já está no ciclo 146). Esta ADR abre a faixa
avulsa nova 0300–0319.
