# ADR 0621 — Studio (CMS) com o visual do Moves OS

- Status: Aceito
- Data: 20/11/2026
- Pedido: Djalma, 20/11/2026 ("pode implementar esse visual no CMS, eu acho que vai ficar mais bonito do que esse roxo. Tira por base essa print screen ... da tela do OS"), com print do painel do Moves OS (sistema próprio dele).

## Contexto

CONFIRMADO no repositório: o Studio usa a moldura do ERP (ADR 0026) pela camada
`container/cms/studio/v1/assets/css/connect-shell.css`, com paleta própria em
`body[data-environment="studio"]` (roxo #6b1fa8, sidebar roxo-escura #2a0f40). O Help Desk usa o
mesmo layout com paleta azul e o ERP segue o padrão Realizze verde.

O print do Moves OS mostra: sidebar quase preta, item ativo em degradê rosa → roxo com brilho,
cards brancos de borda suave, atalhos com ícone em quadrado colorido (rosa e roxo), tabelas com
cabeçalho em maiúsculas espaçadas e fonte tipo Inter. O Moves OS é do próprio Djalma; não há
cópia de código, só a direção visual.

## Decisão

1. Só o Studio muda. Help Desk (azul) e ERP (verde) ficam como estão.
2. A paleta do Studio em `connect-shell.css` passa a: roxo `#7c22d4` (marca), sidebar `#13121f`,
   fundo `#f3f4f7`, bordas `#e7e8ef`.
3. Nova camada `assets/css/moves-os.css`, carregada por `layouts/studio.php` depois de
   `connect-shell.css`. Toda regra começa por `[data-environment="studio"]` (o teste confere), e
   ela só mexe em cor, sombra e forma: item ativo do menu e botão principal em degradê rosa → roxo,
   ícones dos indicadores e das ações rápidas em quadrados com degradê (rosa e roxo alternados),
   cards de 10 px de canto com borda, cabeçalho de tabela limpo, navbar sobre o fundo da tela,
   rodapé centralizado.
4. Nenhum HTML, rota ou permissão muda. A busca continua na navbar (no Moves OS fica na sidebar)
   para não mexer na estrutura do layout compartilhado com o Help Desk.

## Acessibilidade

- Texto branco sobre o rosa `#d6196f` dá 5,0:1 e sobre o roxo `#7c22d4` dá 6,9:1 (AA).
- Roxo `#7c22d4` sobre branco dá 6,9:1; o subtítulo das páginas usa `#6a1bb8`.
- Texto da sidebar `#cfcde0` sobre `#13121f` dá cerca de 12:1.
- Foco pelo teclado visível: contorno rosa-claro na sidebar e roxo no conteúdo (`:focus-visible`);
  o teste garante que a camada não tem `outline: 0`.

## Consequências

- Os prints antes/depois ficam em `/mnt/project-files/cms-moves-os/`.
- Voltar ao roxo antigo = remover o `<link>` de `moves-os.css` e restaurar o bloco da paleta.
- Testes: `tests/legacy/Unit/StudioErpShellTest.php` (paleta e escopo da camada) e
  `tests/studio/render-smoke.php` (renderização das telas).
