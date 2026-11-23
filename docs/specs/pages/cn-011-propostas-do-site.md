# CN-011 — Propostas do site

Origem: pedido do proprietário em 22/11/2026 ("ao solicitar a proposta dentro do site, ela poderia cair dentro do ERP,
talvez em Comunicados, com um alerta para a gente saber que chegou"). Não existe no inventário APControle; é página
própria da Connect. Decisões: ADR 0639 (Studio > Gestão > Propostas e aviso no sininho) e ADR 0644 (esta tela).
Rota: `/erp/comunicacao/propostas` (Comunicação › Comercial).
API: `GET /api/v1/proposals`, `GET /api/v1/proposals/{id}`, `POST /api/v1/proposals/{id}/status`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Lê a mesma tabela `proposals` do Studio; o pedido continua chegando só pelo formulário do site (`ProposalService::submit`) | CONFIRMADO |
| Acesso: `erp.access` + `proposals.manage` (a mesma permissão do Studio e de quem recebe o aviso) | CONFIRMADO |
| Alerta no topo da tela e número no menu lateral (item e grupo Comunicação) enquanto houver propostas com situação Nova | CONFIRMADO |
| O aviso do sino aberto no ERP leva à proposta no ERP; aberto no Studio, à proposta no Studio | CONFIRMADO |
| Busca por nome, e-mail, condomínio ou protocolo; filtro por situação; 20 por página com endereço `/p/N` | CONFIRMADO |
| Quadros: recebidas, novas, em atendimento (contatada, qualificada, proposta enviada) e fechadas | CONFIRMADO |
| Mudar situação e responsável com CSRF; responsável só da equipe com `proposals.manage`; Contatada grava a data do primeiro contato | CONFIRMADO |
| Toda mudança entra na auditoria (`proposals`, antes e depois) | CONFIRMADO |
| Mostra se a pessoa aceitou receber novidades (ADR 0604) | CONFIRMADO |
| Gerar e enviar a proposta em PDF fica no Studio (botão "Responder no Studio"); o ERP lista as respostas geradas | CONFIRMADO |

## Fora do escopo

- Propostas não são de um condomínio cadastrado: não há `condominium_id` nem filtro pela barra superior.
- Excluir pedido: não existe (guarda pela Política de Privacidade, até 2 anos sem contrato).

## Estados

- Sem banco: "Propostas indisponíveis".
- Sem pedidos: "Nenhuma proposta encontrada".
- Sem permissão: 403 "Você não tem permissão para ver as propostas do site." e o item some do menu.
