# 0644 — Propostas do site também no ERP, com alerta de proposta nova

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0639 (propostas em Studio > Gestão, aviso no sininho), 0604 (aceite no pedido de proposta),
  0142 (sino do ERP com as notificações do Studio), 0398 (menu do ERP), 0141 (Central de Ajuda).
- Numeração: faixa avulsa 0600–0799; 0643 está no branch do login único do Talk (outra thread). Conferidos índice e
  branches em 22/11.

## Contexto
O Djalma pediu que o pedido de proposta feito no site caia também dentro do ERP ("talvez em Comunicados"), com um
alerta para a equipe saber que chegou. Hoje (ADR 0639) o pedido grava em `proposals`, aparece em Studio > Gestão >
Propostas e manda aviso no sininho para quem tem `proposals.manage`. O sino do ERP já mostra os mesmos avisos
(ADR 0142), mas o aviso levava ao Studio e não havia tela nem sinal no menu do ERP.

## Decisão
1. Nova tela **Propostas do site** (CN-011) em **Comunicação › Comercial**, primeira do grupo, em
   `/comunicacao/propostas`. Comunicação é onde o Djalma procurou; a seção "Comercial" deixa claro que não é
   comunicado para morador.
2. A tela lê e atualiza a mesma tabela pelo serviço `ProposalDesk` (lista, quadros, detalhe, situação e
   responsável). O Studio passa a gravar o acompanhamento pelo mesmo serviço, que também valida o responsável e
   registra a auditoria. Gerar e enviar o PDF continua só no Studio; o ERP mostra as respostas e leva até lá.
3. **Alerta**: enquanto houver propostas com situação **Nova**, aparece um número no item do menu (e no nome do
   grupo Comunicação quando ele está fechado) e um aviso no topo da tela. O número some quando todas saem de
   "Nova". O aviso do sino continua (é um dos avisos básicos que o Djalma quer manter).
4. O aviso do sino aberto no ERP leva à proposta no ERP (`Inbox::open` troca `/studio/proposals/{id}` pelo
   endereço do ERP para quem pode abrir a tela). No Studio, nada muda.
5. Permissão: `erp.access` + `proposals.manage`, a mesma do Studio. Sem migration.
6. API REST junto: `GET /api/v1/proposals`, `GET /api/v1/proposals/{id}` e `POST /api/v1/proposals/{id}/status`.

## Consequências
- Quem trabalha no ERP vê a proposta nova sem abrir o Studio.
- A consulta do número do menu roda a cada tela do ERP, só para quem tem a permissão, pelo índice
  `idx_proposals_status_created`.
- Testes: `ErpProposalScreensTest` (unidade), `ProposalDeskIntegrationTest` (integração) e o caso CN-011 do
  `tests/erp/render-smoke.php`.
- Artigo na Central de Ajuda: `comunicacao/propostas-do-site.md`; o artigo do Studio aponta para ele.
