# Moves Editor (1.0.1-connect)

Editor de texto da Moves (autor: Djalma Martins), uso interno do Connect ERP. Base: pacote `moves-editor-v1.0.0.zip`
enviado em 20/11/2026; o que mudou está no ADR 0610 (`docs/Organic Code/decisions/0610-moves-editor.md`).

- `moves-editor.js` — o componente (`new MovesEditor(alvo, opções)`); opções do Connect: `toolbar: 'full'|'basic'`, `html: false`.
- `moves-editor.css` — só os estilos do editor (a página de demonstração do pacote ficou de fora).
- `moves-editor-boot.js` — liga o editor em `<textarea data-moves-editor="full|basic">` e expõe `window.MovesEditors`
  (`get`, `insert`, `set`, `mount`).

O HTML do editor é limpo de novo no servidor (`Source\Support\RichHtml`) antes de gravar e de mostrar.
