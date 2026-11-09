# CN-004 — Chamados (abertura para o Help Desk)

Origem: pedido do proprietário (Djalma, 09/11/2026). Não existe no inventário APControle; é página própria da Connect.
Rota: `/erp/operacional/chamados` (grupo Operacional › Rotina). Decisão: ADR 0034. API: `/api/v1/tickets`.

## Requisito (CONFIRMADO, palavras do proprietário)

"Toda vez que tivesse um chamado, cair e ir para o Help Desk. Aí todas as informações."

## Regras implementadas

| Regra | Situação |
|---|---|
| Todo chamado (ERP, Help Desk, site, API e, no futuro, portal) grava na fila do Help Desk pelo mesmo serviço | CONFIRMADO |
| Chamado leva condomínio, unidade, solicitante (nome, e-mail, telefone) e origem | CONFIRMADO ("todas as informações") |
| Condomínio só do escopo de quem abre; unidade precisa ser do condomínio | INFERIDO (ADR-002) |
| Condomínio pré-selecionado pelo contexto da barra superior | INFERIDO |
| Solicitante padrão é o próprio usuário; pode ser um morador sem conta | INFERIDO |
| SLA pela prioridade: urgente 24 h, alta 48 h, média 72 h, baixa 120 h (mesmo do Help Desk) | CONFIRMADO (regra existente) |
| Equipe (níveis 5+), responsável e solicitante com conta são avisados por notificação e e-mail | CONFIRMADO (regra existente) |
| Lista no ERP: chamados dos condomínios do escopo e os abertos pelo usuário; atendimento só no Help Desk | INFERIDO |

## PENDENTE (para o proprietário)

- O ERP deve mostrar as respostas do Help Desk (sem as notas internas)?
- Anexos na abertura pelo ERP?
- Categorias fixas de chamado por tipo (manutenção, financeiro, portaria)?

## Estados

Lista vazia, filtros, erro por campo (assunto, descrição, condomínio fora do escopo, unidade de outro condomínio,
e-mail inválido), sucesso com protocolo, 403 sem `erp.access`.
