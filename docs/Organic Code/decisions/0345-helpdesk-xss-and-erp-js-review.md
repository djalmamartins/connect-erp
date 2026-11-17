# 0345 — XSS no Help Desk e revisão do JavaScript do ERP

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7 (XSS); continuação do ADR 0344; Djalma, 16/11/2026: "tome as melhores decisões"

## Contexto

O ADR 0344 cobriu o que o ERP renderiza no servidor. Faltavam o Help Desk (fila e ficha do chamado, onde chega texto de
qualquer pessoa: formulário do site, app do morador, Talk) e o que o JavaScript do ERP monta a partir da API.

## Decisão

1. `HelpDeskXssCanaryIntegrationTest` grava `"'></script><i data-xss>x` no assunto, na descrição, no nome e e-mail de quem
   pediu, na categoria, nas etiquetas, na conversa e na nota interna, no nome dos anexos, no histórico, nas respostas rápidas,
   no nome do condomínio e do atendente. A fila e a ficha do chamado precisam mostrar o texto (prova que a linha chegou) e
   nunca a marca crua.
2. Revisão do `erp.js` (73 pontos com `innerHTML`): todo valor que vem da API passa pela função `esc()` (que usa
   `textContent`), inclusive nomes de arquivo, nomes de conta, protocolos, unidades e mensagens de erro; o que entra sem
   `esc()` são números, datas cortadas de campos `AAAA-MM-DD`, ids e textos fixos. Nada a corrigir.

## Resultado

Nenhum texto cru no Help Desk. Para conferir que o teste pega a falha, o escape do nome do anexo na ficha foi tirado por um
instante: o teste falhou ("Texto gravado saiu sem escapar na ficha do Help Desk"). O escape foi devolvido.

## Fora desta fatia

O site público, a Central de Ajuda e o app web do morador.
