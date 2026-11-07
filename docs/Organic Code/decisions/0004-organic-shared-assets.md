# 0004 — Distribuição Organic em container/shared e fim de organic/ e src/

Data: 2026-11-07 · Status: aceita

## Contexto

A raiz do Connect tinha duas pastas fora do padrão Organic Code:

- `organic/`: distribuição do Organic V2 (bundles CSS/JS, adaptadores
  `compat-v1`, fontes, marca e mascote), mais uma cópia do `organic-editor`
  idêntica à do Studio e os módulos-fonte dos componentes, já embutidos nos
  bundles. Os layouts WEB, o tema de Suporte e `Services/Minify/{App,Erp}.php`
  carregavam arquivos de `/organic`.
- `src/`: pasta vazia, não versionada e sem referência no autoload.

O contrato diz que assets ficam em `assets/` do tema ou na área compartilhada
`container/shared/assets/`.

## Decisão

1. A distribuição passa para `container/shared/assets/organic/`, servida em
   `/container/shared/assets/organic/...`. Fontes continuam em `fonts/` ao
   lado do CSS, preservando os `url(fonts/...)` relativos.
2. `organic/editor/` foi removida: o Studio já usa
   `container/cms/studio/v1/assets/vendor/organic-editor/` (conteúdo idêntico).
3. `organic/modules/` foi removida: são os fontes ES dos componentes, já
   empacotados em `organic*.js`; o fonte oficial vive em `../organic-v2`.
4. `brand/` e `mascot/` saem de `assets/organic/` e ficam na raiz da distribuição.
5. `service/commands/sync-organic-v2.php` sincroniza no novo destino.
6. `src/` foi apagada.

## Consequências

- Nenhuma URL `/organic/...` existe mais; quem consumir o Organic deve usar o
  caminho compartilhado.
- O checkout `main` (servido em www.connect.lab) só reflete a mudança após o
  merge de `claude-dev`.
