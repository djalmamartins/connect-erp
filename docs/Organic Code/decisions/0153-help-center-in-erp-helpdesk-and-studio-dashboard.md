# 0153 — Central de Ajuda dentro do ERP e do Help Desk, e atendimento no painel do Studio

Data: 2026-11-10 · Status: aceito (pedido do Djalma, "vamos seguir" na integração; decisões tomadas sem ele, por delegação) · CN-004, ADR 0034, 0141 e 0142

## Contexto

O ADR 0142 ligou os chamados entre ERP e Help Desk. O que ainda faltava para os três ambientes parecerem um
produto só era o CMS: a Central de Ajuda (artigos do Studio, ADR 0141) só aparecia no site e no item "Ajuda desta
página" do menu Suporte, e o painel do Studio mostrava só conteúdo e acessos do site, sem nada do atendimento.

O login único já existe: entre hosts a troca usa código de uso único (ADR 0034) e "Sair" encerra a sessão nos
outros hosts (ADR 0036). Nada mudou nessa parte.

## Decisão

1. **Sugestão de artigos no chamado do ERP.** Enquanto a pessoa digita o assunto (4+ letras, espera de 400 ms), o
   formulário mostra até 4 artigos publicados da Central que combinam com qualquer palavra do assunto, com link
   para o artigo no site (nova aba). O chamado segue normalmente se o artigo não resolver.
   API: `GET /api/v1/help/articles?q=&limit=` (exige `erp.access`, limite 120 por minuto).
2. **Artigo da Central na resposta do Help Desk.** O compositor de resposta ganhou uma busca de artigos (começa
   com o assunto do chamado). O clique cola "Título: link" no texto da resposta. Rota
   `GET /helpdesk/tickets/artigos?q=` (exige `support.manage`).
3. **Regra única da busca** (`Source\Services\Support\HelpSuggestions`): só artigo publicado, com data de
   publicação já passada e categoria ativa. Palavras de 2+ letras, no máximo 6, as mais longas primeiro, no título,
   no resumo ou no texto. O título conta primeiro e depois os mais vistos. É a mesma base da busca pública
   (/suporte/buscar). O link do artigo é sempre o do site, que é público.
4. **Atendimento no painel do Studio.** Para quem tem `support.manage`, o dashboard do Studio mostra novos e em
   atendimento, SLA vencido, aguardando cliente e chamados vindos do ERP em 7 dias
   (`TicketService::queueSummary`). Mostra também os 5 abertos mais urgentes, com condomínio e unidade, e os
   botões "Fila do Help Desk" e "Abrir o ERP" (este para quem tem `erp.access`). Se a consulta falhar, o painel some
   e o erro vai para o log.

Sem tabela nova e sem migration.

## Consequências e lacunas

- A busca é por LIKE, como a pública. Com muitos artigos, um índice FULLTEXT pode ser preciso.
- O Studio não conta quantos chamados foram evitados pela sugestão; dá para medir depois pelas visualizações.
- Comunicados do ERP e FAQ do Studio continuam separados. O FAQ do site é outro conteúdo, mantido no Studio.
