# 0316 — Modais do sistema no lugar dos avisos do navegador (etapa 1: ERP) e avisos acima das modais

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 ("fui cancelar no banco agora um boleto. Aí vai e abre um pop-up do próprio
  navegador [...] queria que fosse uma modal, informando o que está acontecendo [...] o que vai acontecer, mas
  sempre do próprio sistema"; e "as mensagens flash [...] tem que ficar acima de [tudo], z-index: 99999")
- Relacionadas: ADR 0120 (avisos no canto superior direito), 0156 (boletos do Inter)

## Contexto

Várias telas usavam `window.confirm()`, `window.alert()` e `window.prompt()`. A janela do navegador não segue o
visual do ERP, mostra o endereço do site no título, não diz se a ação pode ser desfeita e não deixa escolher o
texto dos botões ("OK"/"Cancelar" em "Cancelar o boleto?" confunde). O levantamento achou pontos
no ERP, no app web do morador, no Studio/Help Desk, no editor de texto do Studio e no organic.js.

## Decisão

1. **Componente único no ERP** (`erp.js`, sem dependência), com `<dialog>` modal e as classes `erp-modal`:
   - `ErpUI.confirm({ title, message, undo, undoText, confirmLabel, cancelLabel, tone })` devolve uma
     `Promise<boolean>`. A modal mostra o título (a pergunta), o que vai acontecer (uma linha por parágrafo) e
     uma faixa **"Dá para desfazer."** (verde) ou **"Não dá para desfazer."** (vermelha) com o complemento.
   - `ErpUI.alert({ title, message })` para recados (um botão "Entendi").
   - `ErpUI.copy(label, valor)` substitui o `prompt()` usado quando o navegador não deixa copiar: o texto
     aparece selecionado num campo só de leitura.
   - Tom `danger` (botão vermelho) começa com o foco em **Voltar**, para um Enter distraído não confirmar. Esc,
     Voltar e clique fora fecham sem fazer nada. Os textos entram com `textContent`, nunca como HTML.
2. **Formulários e botões declarativos**: `data-erp-confirm` (formulário) e `data-confirm` (botões das telas de
   assembleia e acessos) usam a mesma modal. A frase até o primeiro "? " vira o título e o resto vira a
   explicação. Complementos opcionais: `data-confirm-undo="sim|nao"`, `data-confirm-undo-text`,
   `data-confirm-action` (texto do botão) e `data-confirm-tone`. Depois do sim, o formulário é enviado de novo
   pelo mesmo botão (`requestSubmit(submitter)`), preservando `name`/`value`.
3. **Todas as perguntas do ERP trocadas**, cada uma dizendo se dá para desfazer. O exemplo do Djalma:
   "Cancelar o boleto N no banco?" explica que o boleto e o Pix deixam de poder ser pagos, que a conta a receber
   continua em aberto e que dá para emitir outro boleto; "Não dá para desfazer: o banco não reativa um boleto
   cancelado"; botões **Manter boleto** e **Cancelar boleto**.
4. **Avisos acima de tudo**: `.erp-toasts` passa a `z-index: 99999`. Como a `<dialog>` modal fica na camada do
   topo do navegador (acima de qualquer z-index), a região dos avisos vira `popover="manual"` e é reaberta a cada
   aviso novo e a cada modal aberta, ficando por cima dela. Navegador sem popover fica com o z-index.
5. Teste `ErpSystemDialogsTest` falha se alguma tela do ERP voltar a chamar `alert(`, `confirm(` ou `prompt(`.

## Etapas seguintes (uma por vez, pedido do Djalma até 19/11)

- Etapa 2: app web do morador (`container/app`, 3 pontos) e Studio/Help Desk (`container/cms`, ~20 pontos,
  incluindo o `data-confirm-submit` do layout), com um componente compartilhado no estilo de cada sistema e
  os avisos `connect-notice.js` também em 99999 acima das modais.
- Etapa 3: editor de texto do Studio (prompts de link, tabela e HTML) e o `organic.js`.
- O app React Native (`apps/morador`) já usa o diálogo nativo do sistema operacional por `lib/confirm.ts`,
  que não é janela de navegador: fica como está.

## Consequências

- Uma pergunta nova no ERP usa `ErpUI.confirm` (ou `data-erp-confirm` com `data-confirm-undo`) e precisa dizer se
  dá para desfazer.
- Depois de mexer em `erp.js`/`erp.css`, gerar de novo `erp.min.*` (ErpBundleFreshnessTest).
- Sem mudança de banco, rota, API ou permissão. Nenhuma página nova: não há artigo novo na Central de Ajuda.
