# 0120 — Aviso de sucesso com a marca da Connect virando check

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR-003 (design system), 0141 (Central de Ajuda)

## Contexto

Depois de salvar um cadastro, o ERP mostrava a mensagem de sucesso numa faixa verde no topo da página. As telas que
gravam pela API mostravam um aviso pequeno no canto (`ErpUI.toast`). O Djalma pediu, em 11/11/2026, que toda vez que
um cadastro der certo apareça um aviso com a animação da marca: a logo gira, o traço dela vira o check e o texto diz
o que foi feito. A animação toca **uma vez só** e para no check, sem repetir.

## Decisão

- `erp.js` ganhou `ErpUI.done(mensagem)`: um cartão no centro da tela com o ícone animado e a mensagem. Fecha sozinho
  em 3,2 s, com clique ou com Esc. Ele entra na região `[data-erp-toasts]` (`aria-live`), então o leitor de tela lê o
  texto.
- O ícone é um SVG embutido no próprio `erp.js` (cerca de 3 KB), animado com SMIL (`fill="freeze"`, cerca de 1,6 s): a
  marca dourada, um esmaecimento curto para o traço, o giro anti-horário com o traço se desenrolando até o check e um
  pulso suave no fim. Depois do pedido de "mais fluido" (11/11), o traço passou a ser amostrado por comprimento
  (25 pontos em cada metade, a ponta da gota indo para o vértice do V), com uma só curva de easing para giro e forma e
  um atraso de 0,25 s para o carregamento da página não engolir os primeiros quadros.
  SMIL funciona em todos os navegadores atuais e não depende de CSS `d: path()`, que o Safari não anima.
- A mensagem de sucesso do servidor (`Message::success()->flash()`), que já é usada por todos os cadastros, vira esse
  aviso: o `erp.js` acha `.erp-flash .message.success` ao carregar, tira a faixa e mostra o cartão. Sem JavaScript, a
  faixa continua aparecendo como antes. Avisos de erro, alerta e informação seguem na faixa.
- `ErpUI.toast(mensagem, 'success')` também usa o cartão. Avisos curtos que não são gravação passam
  `{ quiet: true }` e continuam no canto (hoje só o "Copiado").
- Com "reduzir movimento" ligado no sistema, o ícone já aparece parado no check.

## Consequências

- Nenhum controller mudou: as mensagens de sucesso existentes passam a usar o aviso.
- Uma gravação que hoje não manda mensagem de sucesso não mostra o aviso. Para ela aparecer, a tela precisa mandar a
  mensagem (`flash` ou `ErpUI.toast`).
- Os bundles `erp.min.js` e `erp.min.css` foram gerados de novo (ErpBundleFreshnessTest).
- Teste: `tests/legacy/Unit/ErpSuccessCheckTest.php`.
