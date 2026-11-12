# 0120 — Aviso de sucesso com a marca da Connect virando check

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR-003 (design system), 0141 (Central de Ajuda)

## Contexto

Depois de salvar um cadastro, o ERP mostrava a mensagem de sucesso numa faixa verde no topo da página. As telas que
gravam pela API mostravam um aviso pequeno no canto (`ErpUI.toast`). O Djalma pediu, em 11/11/2026, que toda vez que
um cadastro der certo apareça um aviso com a animação da marca virando check e o texto dizendo o que foi feito. A animação toca **uma vez só** e para no check, sem repetir.

## Decisão

- Todos os avisos do ERP viraram cartões no canto superior direito, logo abaixo da barra superior (pedido do Djalma
  em 11/11, com uma imagem de referência de estilo): fundo claro na cor do tipo (sucesso verde, alerta amarelo, erro
  vermelho, informação azul), ícone redondo à esquerda, título em negrito ("Sucesso!", "Atenção!", "Erro!",
  "Informação"), a mensagem embaixo e um X para fechar. Somem sozinhos (sucesso 4,5 s, informação 6 s, alerta 8 s,
  erro 9 s), e o tempo para enquanto o mouse está em cima. Esc fecha o mais recente. No máximo três ficam abertos ao
  mesmo tempo. A região `[data-erp-toasts]` (`aria-live`) faz o leitor de tela ler o texto, e erro e alerta usam
  `role="alert"`.
- O ícone de sucesso é a animação da marca, em branco sobre o círculo verde.
- O ícone é um SVG embutido no próprio `erp.js` (cerca de 3 KB), animado com SMIL (`fill="freeze"`, cerca de 1,9 s),
  no desenho que o Djalma descreveu em 11/11: a própria marca, cheia e com o formato dela, se apaga a partir da haste
  (ponta esquerda), contornando a gota até o corte do braço direito no alto. O apagamento é uma máscara com um traço
  pelo eixo da marca, para a logo não virar um contorno fino no caminho. O que sobra vira uma bolinha que cai até o
  começo do check, e o check é desenhado a partir dali, com um pulso suave no fim. Começa 0,2 s depois de o aviso aparecer, para o carregamento da
  página não engolir os primeiros quadros.
  SMIL funciona em todos os navegadores atuais e não depende de CSS `d: path()`, que o Safari não anima.
- Toda mensagem do servidor (`Message::success|warning|error|info()->flash()`) vira esse aviso: o `erp.js` acha
  `.erp-flash .message` ao carregar, tira a faixa e mostra o cartão do tipo certo. Sem JavaScript, a faixa continua
  aparecendo como antes.
- `ErpUI.toast(mensagem, tom)` usa o mesmo cartão (`danger` vira erro; sem tom, informação). Avisos de sucesso que não
  são gravação passam `{ quiet: true }` e mostram o check parado, sem a animação (hoje só o "Copiado").
  `ErpUI.done(mensagem)` é o atalho do sucesso. Cada aviso de sucesso tem a própria máscara (`erp-done-mask-N`).
- Com "reduzir movimento" ligado no sistema, o ícone já aparece parado no check.

- **Todo o sistema (12/11):** `Message::render()` passou a marcar a mensagem com `data-notice`. O componente
  compartilhado `container/shared/assets/js/connect-notice.js` (sem dependências, com o próprio CSS) transforma
  toda `.message[data-notice]` em aviso no canto, inclusive as que chegam depois por AJAX (MutationObserver), e
  expõe `ConnectNotice.show(mensagem, tipo)`. Ele entra nos bundles do Studio (`studio.min.js`) e do site
  (`site.min.js`) e na tela de login do Studio. Os `div.message` escritos à mão, como os estados vazios, não têm
  `data-notice` e continuam como estão. O ERP mantém a mesma interface no próprio `erp.js`, porque o bundle do ERP
  só usa arquivos do tema. Ele também converte o flash que algumas páginas imprimem no conteúdo.

## Consequências

- Nenhum controller mudou: as mensagens de sucesso existentes passam a usar o aviso.
- Uma gravação que hoje não manda mensagem de sucesso não mostra o aviso. Para ela aparecer, a tela precisa mandar a
  mensagem (`flash` ou `ErpUI.toast`).
- Os bundles `erp.min.js` e `erp.min.css` foram gerados de novo (ErpBundleFreshnessTest).
- Teste: `tests/legacy/Unit/ErpSuccessCheckTest.php`.
