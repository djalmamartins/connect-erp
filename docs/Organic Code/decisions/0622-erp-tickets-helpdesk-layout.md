# 0622 — Chamados do ERP com o desenho da fila e do atendimento do Help Desk

- **Status:** aceito
- **Data:** 2026-11-20
- **Relacionados:** 0034 (todo chamado no Help Desk), 0142 (chamado no ERP com conversa), 0305 (ocorrência é
  chamado), 0307 (atendimento do Help Desk no ERP), 0610 (Moves Editor), 0316 (modais do sistema).

## Contexto

O Djalma achou a tela de chamados do ERP (Atendimento › Chamados, rota `/operacional/chamados`) bagunçada:
o formulário "Abrir chamado" ocupava o alto da página, a fila vinha depois com oito filtros soltos e a ação em
lote numa linha separada; e o chamado aberto tinha um desenho diferente do atendimento do Help Desk. Ele pediu
o mesmo padrão de colunas da fila e da tela de um chamado do Help Desk, mantendo o estilo do ERP (verde,
padrão Realizze, campos de 40 px).

## Decisão

Só as telas mudam (sem migration, sem rota nova, sem mudança de serviço ou de permissão):

- **Fila** (`pages/tickets/index.php`): cabeçalho "Atendimento › Chamados" com **Fila do Help Desk** e
  **Abrir chamado**; contadores; um cartão com a barra de ferramentas do Help Desk (busca com lupa,
  condomínio, prioridade, responsável, prazo, origem e tipo à esquerda, **Com os selecionados…** à direita) e a
  tabela com as mesmas colunas da fila do Help Desk. O filtro de situação sai da barra porque os contadores já
  fazem isso (a situação escolhida segue nos filtros num campo oculto). O formulário de abertura vai para um
  modal (`dialog#tk-new`), que reabre sozinho quando o envio volta com erro (`data-erp-modal-auto`). O lote
  sem nenhum chamado marcado avisa com a modal do sistema.
- **Chamado** (`pages/tickets/show.php`): o desenho do atendimento do Help Desk. À esquerda, o pedido no alto
  (avatar, quem pediu, protocolo, assunto e texto), as respostas em cartões com avatar e selo
  (Enviada, Recebida ou Interna) e o compositor (abas Responder/Nota interna, cronômetro, resposta rápida,
  anexos, Resolver após enviar). À direita: **Propriedades do chamado** (triagem), **Detalhes do
  chamado** (inclui quem pediu, e-mail e telefone, antes num cartão à parte), **Histórico de alterações** e
  **Resolver/Reabrir chamado**. Quem não atende vê a conversa, o campo de resposta e os detalhes.
- CSS só no bloco `erp-ticket-*` do `erp.css` (cores e medidas do ERP); `erp.min.*` regerados.
- Artigo da Central de Ajuda `operacional/chamados` atualizado (modal, filtros e a nova tela do chamado).

## Consequências

- O Help Desk (azul) não muda. As duas telas agora se leem do mesmo jeito, então quem alterna entre ERP e
  Help Desk encontra cada coisa no mesmo lugar.
- Os prints do artigo precisam ser regerados no Mac com `bash tools/help/prints.sh --only chamados`.
- Testes: `tests/erp/render-smoke.php` (CN-004) e a suíte unitária; sem integração nova, porque não há
  mudança de dados.
