# 0320 — Modal do sistema no lugar dos avisos do navegador em todo o sistema (etapa 2)

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 ("olha o que no sistema tem o mesmo comportamento e altera"), continuação da ADR 0316
- Relacionadas: ADR 0316 (modal do ERP), 0120 (avisos no canto), 0302 (app web do morador)

## Decisão

1. **Componente compartilhado** `container/shared/assets/js/connect-dialog.js` (sem dependências, com o CSS
   embutido e as cores do ERP), carregado no `studio.min.js` (Studio e Help Desk), no `site.min.js` e no
   `scripts.js` do app web do morador:
   - `ConnectDialog.confirm`, `alert`, `prompt` (campo de texto, número ou HTML) e `copy`, com as mesmas regras da
     0316: o que vai acontecer, faixa "Dá / Não dá para desfazer", foco em Voltar quando é perigoso, Esc e clique
     fora cancelam, texto sempre como texto.
   - Declarativo: `data-cn-confirm` no formulário ou no botão de envio (com `data-confirm-undo`, `-undo-text`,
     `-action`, `-tone`). Com o sim, o formulário é reenviado pelo mesmo botão (`requestSubmit(submitter)`), e o
     envio por AJAX do Studio continua recebendo o `name/value` do botão.
2. **Trocados**: exclusões do Studio (blog, categorias, mídia, seleção de mídia, depoimentos, destaques,
   perguntas, canais, agenda, páginas, Central de Ajuda), envio de proposta, redefinir 2FA de um usuário, avisos de
   "marque ao menos um" (logs e chamados do Help Desk), `data-post` com `data-confirm` do scripts.js, o
   `data-confirm-submit` do layout (removido, virou `data-cn-confirm`), o editor de texto do Studio (link, tabela,
   editar HTML, erro de envio de imagem, conteúdo vazio) e os planos do editor Organic usados pelo Studio
   (aplicar template, apagar rascunho). No app web do morador: os 5 `data-app-confirm` (reserva, visita, inquilino,
   2FA, foto) e o "copiar código" do boleto/Pix.
3. **Avisos acima de tudo**: `connect-notice.js` com `z-index: 99999` e a região como popover reaberta a cada aviso
   e a cada modal (`ConnectNotice.raise`); `.app-toast` do app com `z-index: 99999` e popover.
4. `SystemDialogsEverywhereTest` falha se aparecer `alert(`, `confirm(` ou `prompt(` no código do Connect
   (app, cms, web, send, erp e shared/js), fora de bundles gerados e bibliotecas de terceiros.

## Fora

- `container/shared/assets/organic/organic*.js` é a distribuição gerada do Organic V2 (código-fonte fora deste
  repositório, sincronizado por `service/commands/sync-organic-v2.php`): os dois `confirm` dela ficam para o
  repositório do Organic. Os plugins do editor no Studio (`vendor/organic-editor/modules/plugins/workspace` e
  `persistence`) foram ajustados aqui e marcados com comentário: manter o ajuste ao sincronizar.
- O app React Native (`apps/morador`) usa o diálogo nativo do sistema operacional, que não é janela do navegador.

## Consequências

- Pergunta nova fora do ERP usa `ConnectDialog` ou `data-cn-confirm`; no ERP, `ErpUI` (0316).
- Depois de mexer em `connect-dialog.js` ou `connect-notice.js`, regerar `studio.min.js`, `site.min.js` e o
  `scripts.js` do app. Sem mudança de banco, rota, API ou permissão; nenhuma página nova.
