# 0610 — Moves Editor no lugar do TinyMCE e do Organic Editor

- Status: Aceito (etapa 1: Studio e Central de Ajuda; etapa 2: chamados e comunicados)
- Data: 20/11/2026
- Relacionados: ADR 0346 (limpeza do HTML do Studio), 0316/0320 (modal do sistema, sem alert), 0026 (moldura do Studio)
- Número: 0601–0609 ficaram livres para as threads abertas em 20/11 que já usavam a faixa 0600 (evitar colisão).

## Contexto
Em 20/11/2026 o Djalma enviou o Moves Editor v1.0.0 (editor de texto próprio, da Moves) e pediu para usá-lo em todo
lugar onde se redige texto (blog, mensagens, e-mails, chamados) e apagar o editor atual. Havia dois editores: o
TinyMCE 4 (`container/shared/assets/vendor/scripts/tinymce`, 1,5 MB, com upload próprio `.mce_upload`) e o Organic
Editor (`container/cms/studio/v1/assets/vendor/organic-editor` + `assets/js/editor.js`), que caía no TinyMCE quando
não carregava.

Conferência do pacote (CONFIRMADO no zip): 9 arquivos, sem dependências, sem chamadas de rede, sem licença
(o autor é o próprio Djalma/Moves, como o resto do repositório, `composer.json` "proprietary"). Problemas achados:
1. Digitar no modo visual mandava o cursor para o começo a cada tecla (`change()` reescrevia o `innerHTML`):
   "abc" virava "cba". Reproduzido no Chromium.
2. `alert()` em 5 lugares (proibido pelos ADRs 0316/0320) e `onclick` inline nos botões Cancelar.
3. O CSS trazia a página de demonstração (`body`, `*`, `.badge`, `.hero`...), que mudaria telas do sistema.
4. Cor e tamanho de letra criavam `<font>`, que o próprio sanitizador apagava.

## Decisão
1. Componente compartilhado em `container/shared/assets/moves-editor/` (versão 1.0.1-connect): `moves-editor.js`
   corrigido, `moves-editor.css` só com os estilos do editor (cores por sistema: Studio roxo, Help Desk azul, ERP verde)
   e `moves-editor-boot.js`, que liga o editor em `textarea[data-moves-editor="full|basic"]`. O textarea continua no
   formulário e recebe o HTML; obrigatório e tamanho máximo são conferidos no envio, com aviso dentro do editor.
2. Correções no editor: digitação sem reescrever o conteúdo (só tira o que é perigoso no lugar), avisos no editor no
   lugar de `alert`, sem `onclick` inline, `<font>` vira `<span style>`, colagem e arraste sempre sanitizados, parágrafo
   com `<p>`, imagem sem endereço some. Barra `basic` (para mensagens) esconde tamanho, alinhamento, imagem, inserções,
   tela cheia e o modo HTML.
3. Etapa 1 (este ADR): blog, páginas, destaques e matérias da Central de Ajuda usam `data-moves-editor="full"`.
   As imagens vão para `POST /studio/media/editor`, que agora aceita quem pode editar artigos, páginas, destaques,
   matérias ou a mídia (antes só `media.manage`). O botão "Inserir imagem da biblioteca" fica embaixo de cada editor.
4. Removidos: TinyMCE inteiro, Organic Editor do Studio, `editor.js`, o `tinyMCE.init` e o tratamento `mce_image` do
   `scripts.js`, as rotas `/studio/blog/image` e `/helpdesk/support/image` (e os métodos `postImage`/`supportImage`),
   os modais `.mce_upload` e o CSS deles; `sync-organic-v2.php` não copia mais o editor do Organic.
5. Servidor: `RichHtml::clean` (ADR 0346) continua limpando antes de gravar e de mostrar; passou a aceitar
   `figure[data-align=left|center|right]` e `font-size` no `style`. Site e Central de Ajuda ganharam o CSS do
   alinhamento das imagens (`pages.css`, `support.css`, `site.min.css` regerado).

## Etapa 2 — chamados e comunicados
6. Barra `basic` em: resposta e nota interna do chamado no ERP e no Help Desk, abertura de chamado (ERP e Help Desk),
   respostas rápidas do Help Desk e texto do comunicado geral (AP-111). O ERP só carrega o editor nas telas que têm
   `data-moves-editor` (`default.php`). A resposta rápida entra na posição do cursor (`MovesEditors.insert/set`).
7. `Source\Support\RichText`: `store()` grava o HTML limpo pela lista curta do `RichHtml::clean($html, true)` (texto,
   listas, links, citação; sem imagem, tabela, título ou iframe) ou o texto antigo como antes (`strip_tags`); `html()`
   mostra (texto antigo vira `nl2br` escapado); `plain()` tira a marcação (listas viram "• ", links "texto (endereço)").
8. Onde o texto sai sem HTML continua texto puro: `TicketService::conversation()/present()` devolvem `message` em texto e
   `message_html` para as telas (API, app do morador, Talk e e-mails do chamado leem `message`); `ResidentTickets`,
   `ResidentPortal::announcements`, o widget do suporte e a API de comunicados (`body` texto e `body_html`). O e-mail do
   comunicado mostra a formatação (`RichText::html`). Tamanho: o limite conta o texto visível e o HTML cabe no TEXT (60 kB).
9. Registros antigos (texto) aparecem como antes; nada no banco muda (sem migration).

## Pendências
- PENDENTE: propostas comerciais (PDF), termo de mudança (palavras-chave e PDF) e respostas das FAQs. Notificações do
  Studio ficam texto: aparecem em avisos curtos montados com `textContent` (sino, modal, ERP).
- O pacote do Organic (`container/shared/assets/organic/organic*.js`) ainda traz o módulo de editor dele, sem uso.
  Esse arquivo é gerado no repositório organic-v2 e copiado por `sync-organic-v2.php`; editar à mão seria desfeito.
- Apps do morador (web e React Native): o morador continua escrevendo em texto simples (campo pequeno no celular).

## Testes
`tests/legacy/Unit/MovesEditorTest.php` e `RichTextTest.php` (editores antigos fora, telas com o editor, correções do componente, CSS sem a
demonstração, HTML do servidor, permissão do envio de imagem) e `SystemDialogsEverywhereTest` passa a varrer a pasta do
editor. Navegador (Playwright/Chromium): digitação, negrito, cor, tamanho, colagem com `onerror`/`script`, barra
`basic`, obrigatório e limite no envio.
