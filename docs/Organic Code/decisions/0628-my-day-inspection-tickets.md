# 0628 — Meu Dia e vistoria que vira chamado

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0013 (vistoria presencial e checklist de chegada), 0034/0305 (todo chamado no Help Desk), 0398 (menu novo),
  0141/0309 (Central de Ajuda e "Sobre esta tela").
- Numeração: faixa avulsa 0600–0799. 0624 e 0627 já estavam usados na main e em PRs abertos (#686, #687, #689).

## Contexto
Pedido do Djalma (20/11/2026), depois do estudo "Meu Dia, vistorias e SAC" (Claude Doc), com o desenho do Meu Dia que ele
enviou: uma tela por funcionário logado com o que fazer no dia, agenda e calendário, e as vistorias dos condomínios a cada
15 dias saindo sozinhas como chamado, antes de instalar a v1.0. O estudo recomendou só a parte que reaproveita o que já
existe (agenda, chamados, planos de vistoria, checklist de chegada) e deixou para a v1.1 o WhatsApp organizando tarefas,
"aguardando terceiros", fornecedor no local, mapa, resumo por e-mail e o app da equipe com notificação.

## Decisão
1. **Vistoria vira chamado.** `inspection_plans.responsible_id` (equipe, nível ≥ 5). `InspectionTickets::run()` roda às 7h
   (`tools/inspection-tickets.php`, scheduler do lab e `cron.sh daily`): plano ativo que vence em breve ou venceu, sem
   vistoria em andamento, abre um chamado "Vistoria quinzenal – Condomínio" pelo `TicketService::open` (origem `erp`,
   categoria `Vistoria`, solicitante = responsável ou quem criou o plano, prazo 18h do dia devido). `inspection_plan_tickets`
   com chave única (plano, data devida): rodar de novo não repete. Plano sem responsável e sem criador é pulado e contado.
2. **Concluir resolve.** `InspectionService::complete()` chama `InspectionTickets::completed()`: resolve o chamado do plano
   pelo `TicketDesk::update` (evento, auditoria e aviso ao solicitante) e, se houve itens "Com problema", abre um chamado
   "Pendências da vistoria" (prioridade alta) com a lista. Falha aqui é registrada no log e não desfaz a conclusão.
3. **Meu Dia (CN-010, `/erp/meu-dia`).** `Services\Erp\MyDay` só lê o que existe: compromissos da agenda que já existia
   (`studio_calendar_events`, ganha `condominium_id` e `ticket_id`), chamados atribuídos à pessoa (para agendar e aguardando
   cliente), vistorias dos planos dela e itens do checklist de chegada sob a responsabilidade dela (até 45 dias). Semana em
   7 círculos com hoje em dourado; painel da visita com chamados, vencimentos, pendências e última vistoria, e "Iniciar
   visita". "Agendar" cria compromisso ligado ao chamado, que sai de "Para agendar".
4. **Quem vê.** Cada pessoa vê o próprio dia (`erp.access`). Ver o dia de outra pessoa ou marcar para ela exige
   `erp.myday.team` (migration, papéis developer e super_admin). O painel da visita respeita o escopo de condomínios.
5. **API junto.** `GET /api/v1/my-day`, `GET /api/v1/my-day/visit/{condominium_id}`, `POST /api/v1/my-day/events`,
   `POST /api/v1/my-day/events/{id}/status` (OpenAPI, tag MyDay), mesmas regras da tela.
6. **Migration** `20261120_2200_my_day` (idempotente, com `.down.sql`).

## Consequências
- O responsável recebe a vistoria como chamado no sino e por e-mail, como qualquer chamado novo.
- A agenda do Studio continua igual; as colunas novas são opcionais.
- Fica para a v1.1: "Aguardando terceiros", fornecedor no local, mapa, clima, arrastar para agendar, resumo às 7h por
  e-mail, WhatsApp organizando tarefas e o app da equipe com notificação.

## Testes
- Integração: `InspectionServiceTest` (chamado abre uma vez, responsável, prazo, conclusão resolve, pendências viram
  chamado, plano sem dono é pulado) e `MyDayTest` (o que entra no dia, semana, agendar, permissões, escopo, painel).
- `tests/erp/render-smoke.php` (CN-010 dia e semana: escape, CSRF, semana com hoje marcado), `HelpCenterKnowledgeBaseTest`,
  `HelpPageGuidesTest`, OpenAPI.
