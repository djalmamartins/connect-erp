# 0120 — Aviso de sucesso com a marca da Connect virando check

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR-003 (design system), 0141 (Central de Ajuda)

## Contexto

Depois de salvar um cadastro, o ERP mostrava a mensagem de sucesso numa faixa verde no topo da página. As telas que
gravam pela API mostravam um aviso pequeno no canto (`ErpUI.toast`). O Djalma pediu, em 11/11/2026, que toda vez que
um cadastro der certo apareça um aviso com a animação da marca virando check e o texto dizendo o que foi feito. A animação toca **uma vez só** e para no check, sem repetir.

## Decisão

- `erp.js` ganhou `ErpUI.done(mensagem)`: um cartão no centro da tela com o ícone animado e a mensagem. Fecha sozinho
  em 3,2 s, com clique ou com Esc. Ele entra na região `[data-erp-toasts]` (`aria-live`), então o leitor de tela lê o
  texto.
- O ícone é um SVG embutido no próprio `erp.js` (cerca de 3 KB), animado com SMIL (`fill="freeze"`, cerca de 1,9 s),
  no desenho que o Djalma descreveu em 11/11: a própria marca, cheia e com o formato dela, se apaga a partir da haste
  (ponta esquerda), contornando a gota até o corte do braço direito no alto. O apagamento é uma máscara com um traço
  pelo eixo da marca, para a logo não virar um contorno fino no caminho. O que sobra vira uma bolinha que cai até o
  começo do check, e o check é desenhado a partir dali, com um pulso suave no fim. Começa 0,2 s depois de o aviso aparecer, para o carregamento da
  página não engolir os primeiros quadros.
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
