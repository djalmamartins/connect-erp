# CN-010 — Meu Dia

Origem: pedido do proprietário em 20/11/2026 (estudo "Meu Dia, vistorias e SAC", referência visual enviada por ele).
Não existe no inventário APControle; é página própria da Connect.
Rota: `/erp/meu-dia` (menu, logo abaixo do Dashboard). Decisão: ADR 0630.
API: `GET /api/v1/my-day`, `GET /api/v1/my-day/visit/{condominium_id}`, `POST /api/v1/my-day/events`, `POST /api/v1/my-day/events/{id}/status`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Cada pessoa vê o próprio dia; ver o de outra pessoa e marcar compromisso para ela exige `erp.myday.team` | CONFIRMADO |
| Compromissos vêm da agenda que já existe (`studio_calendar_events`), do responsável ou, sem responsável, de quem criou | CONFIRMADO |
| "Para agendar": chamados atribuídos à pessoa, abertos ou em atendimento, sem compromisso marcado; urgente primeiro, depois o prazo | CONFIRMADO |
| "Agendar" num chamado abre o formulário preenchido; o compromisso guarda o chamado e herda o condomínio | CONFIRMADO |
| "Aguardando cliente": chamados atribuídos à pessoa na situação aguardando cliente | CONFIRMADO |
| Vistorias: planos com a pessoa como responsável (sem responsável: quem criou o plano), até 14 dias à frente; vencidas aparecem hoje | CONFIRMADO |
| Documentos e vencimentos: itens do checklist de chegada sob responsabilidade da pessoa, com prazo ou validade em até 45 dias | CONFIRMADO |
| Faixa de 7 dias em retângulos com o dia mostrado no meio (verde, maior; "Hoje" quando é hoje), 3 antes e 3 depois (5 no celular), passados apagados, contagem de itens por dia, setas de um dia por vez, em carrossel (pedido do Djalma, 22/11) | CONFIRMADO |
| Painel da visita só para condomínios do escopo de quem vê: chamados abertos, vencimentos, pendências da última vistoria, última visita, "Iniciar visita" | CONFIRMADO |
| Concluir, cancelar e reabrir compromisso: o responsável, quem criou ou quem tem `erp.myday.team`; tudo auditado | CONFIRMADO |
| Formulários com CSRF e padrão PRG; página sem cache | CONFIRMADO |
| Ficou para trás (ADR 0658): compromisso de dia anterior não concluído (até 30 dias), chamado atribuído com prazo vencido (aberto/em atendimento), vistoria atrasada e item do checklist vencido ficam vermelhos no dia original e aparecem hoje no quadro "Ficou para trás" até fechar | CONFIRMADO |
| Chamado resolvido/fechado tira do atraso o compromisso ligado a ele; chamado agendado aparece uma vez | CONFIRMADO |
| "Sem responsável no SAC": chamados abertos sem ninguém atribuído, no escopo, só para quem tem `support.manage` | CONFIRMADO |
| Selo vermelho de atrasadas no item Meu Dia do menu e aviso "Tarefas atrasadas no Meu Dia" no sininho às 7h, uma vez por dia | CONFIRMADO |
| API: `GET /api/v1/my-day` (overdue, unassigned) e `GET /api/v1/my-day/overdue` | CONFIRMADO |

## Depois da v1.0 (estudo de 20/11/2026)

- Situação "Aguardando terceiros" no chamado, fornecedor no local, aba Mapa, clima e arrastar para agendar.
- Resumo do dia por e-mail às 7h e app da equipe com notificação.

## Estados

- Dia sem compromissos: "Nada marcado neste dia".
- Sem chamados para agendar, sem vistorias ou sem vencimentos: frase curta em cada bloco.
- Nada atrasado: o quadro "Ficou para trás" não aparece.
- Sem vistoria no dia: o painel da visita pede para escolher uma vistoria da lista.
- Pessoa de fora da equipe pedida sem permissão: 403 "Você só pode ver o seu próprio dia".
