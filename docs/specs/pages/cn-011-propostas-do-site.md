# CN-011 — Propostas

Origem: pedido do proprietário em 22/11/2026 ("ao solicitar a proposta dentro do site, ela poderia cair dentro do ERP,
talvez em Comunicados, com um alerta para a gente saber que chegou"). Não existe no inventário APControle; é página
própria da Connect. Decisões: ADR 0639 (Studio > Gestão > Propostas e aviso no sininho), ADR 0644 (esta tela) e
ADR 0650 (26/11/2026: "passar todas as funções de proposta para dentro do ERP, a criação, a geração e o envio", com o
PDF no modelo de proposta da Connect).
Rota: `/erp/comunicacao/propostas` (Comunicação › Comercial).
Rotas extras: `/nova` (GET/POST), `/{id}/resposta` (POST), `/{id}/pdf/{versão}` (GET).
API: `GET /api/v1/proposals`, `POST /api/v1/proposals`, `GET /api/v1/proposals/{id}`, `POST /api/v1/proposals/{id}/status`,
`POST /api/v1/proposals/{id}/responses`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Lê a tabela `proposals`: pedidos do site (`ProposalService::submit`) e propostas cadastradas pela equipe (ADR 0650) | CONFIRMADO |
| Nova proposta: nome, perfil, e-mail, telefone, condomínio, unidades, necessidade, origem e a marca "a pessoa pediu" (obrigatória, LGPD); entra Contatada, sem e-mail e sem aceite de novidades | CONFIRMADO |
| Gerar PDF (versão nova, sem mudar a situação) e Gerar e enviar (confirmação, e-mail com o PDF na fila, situação Proposta enviada salvo Fechada/Perdida/Arquivada) | CONFIRMADO |
| PDF no modelo da Connect (capa, conteúdo, sobre, diferenciais, cronograma, mapa de serviços com o valor, formalização e contato), sem fotos de banco de imagens | CONFIRMADO |
| Studio só consulta: sem formulários; POST responde "agora é no ERP" | CONFIRMADO |
| Acesso: `erp.access` + `proposals.manage` (a mesma permissão do Studio e de quem recebe o aviso) | CONFIRMADO |
| Alerta no topo da tela e número no menu lateral (item e grupo Comunicação) enquanto houver propostas com situação Nova | CONFIRMADO |
| O aviso do sino aberto no ERP leva à proposta no ERP; aberto no Studio, à proposta no Studio | CONFIRMADO |
| Busca por nome, e-mail, condomínio ou protocolo; filtro por situação; 20 por página com endereço `/p/N` | CONFIRMADO |
| Quadros: recebidas, novas, em atendimento (contatada, qualificada, proposta enviada) e fechadas | CONFIRMADO |
| Mudar situação e responsável com CSRF; responsável só da equipe com `proposals.manage`; Contatada grava a data do primeiro contato | CONFIRMADO |
| Toda mudança entra na auditoria (`proposals`, antes e depois) | CONFIRMADO |
| Mostra se a pessoa aceitou receber novidades (ADR 0604) | CONFIRMADO |
| Versões do PDF listadas com "Abrir PDF" (também as antigas feitas no Studio); PDF só pela proposta dona, sem cache | CONFIRMADO |

## Fora do escopo

- Propostas não são de um condomínio cadastrado: não há `condominium_id` nem filtro pela barra superior.
- Excluir pedido: não existe (guarda pela Política de Privacidade, até 2 anos sem contrato).

## Estados

- Sem banco: "Propostas indisponíveis".
- Sem pedidos: "Nenhuma proposta encontrada".
- Sem permissão: 403 "Você não tem permissão para ver as propostas." e o item some do menu.
- Formulário com erro: 422 com a mensagem em cada campo e os textos digitados mantidos.
