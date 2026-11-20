# 0627 — Alternador de ambientes único no ERP, no Studio e no Help Desk

- **Status:** aceito
- **Data:** 2026-11-20
- **Relacionados:** 0034 (alternador e troca entre hosts), 0026 (moldura do ERP no Studio e no Help Desk),
  0620 (Talk fora do ERP, item do alternador leva ao Talk do Pi), 0621 (visual do Moves OS no Studio).

## Contexto

O Djalma mostrou o alternador do Help Desk (botão "Atendimento / Moves Help Desk" no topo da sidebar que abre um
painel branco "Ambientes Connect" com Studio, ERP, Help Desk, Moradores, Talk e Site, o atual destacado e
marcado "Atual") e disse que no ERP a troca ficou diferente: lá era um `<details>` que abria a lista dentro da
sidebar verde, com outros ícones e sem o cartão. Ele pediu tudo no mesmo padrão, mantendo a cor de cada sistema.

## Decisão

Um componente só, usado pelos três sistemas que têm sidebar:

- **Marcação:** `Source\Support\EnvironmentSwitcher::render($user, $ambiente, $linhaPequena, $nome)`. A lista,
  a ordem, quem vê cada item e o link continuam em `Source\Services\Environments::menu()` (ADR 0034); o
  componente só desenha. Ícones SVG próprios, os mesmos em todos os sistemas.
- **Desenho e comportamento:** `container/shared/assets/envswitch/envswitch.css` e `envswitch.js`, carregados
  direto pelo `default.php` do ERP e pelo `layouts/studio.php` (Studio e Help Desk). Botão no topo da sidebar
  (linha pequena + nome + seta), painel branco flutuante logo abaixo, item atual com o fundo claro e o texto na
  cor do sistema e o selo "Atual". Fecha no clique fora, no Esc, no Tab e ao rolar a sidebar; seta para baixo
  abre e as setas andam pelos itens; `aria-expanded` e `aria-current="page"`.
- **Cores:** pelo `data-environment` — ERP verde `#07884c`, Studio roxo `#7c22d4`, Help Desk azul `#2563eb`.
- Saíram o `<details class="erp-envswitch">` do ERP (e o CSS dele) e o painel `studio-system-panel` do
  layout do Studio, com o script inline que o posicionava. As regras antigas do `studio-system-panel` no
  `admin.css`/`studio.min.css` ficaram sem uso (o bundle do Studio não é recompilado, ADR 0026).
- Com a sidebar recolhida o botão some, como antes nos dois sistemas.

## Fora do escopo

- O app do morador (web e celular) e o site não têm o alternador: quem usa é o morador ou o público.
- O Connect Talk roda inteiro no Raspberry Pi (projeto separado, ADR 0620). O item **Talk** continua levando a
  `talk.<domínio>`; se o Talk do Pi ganhar uma sidebar com alternador, ele pode copiar este desenho.

## Consequências

- Sistema novo com sidebar usa o mesmo `render()` e os dois arquivos; cor nova é um bloco `[data-environment]`.
- `EnvironmentSwitcherTest` confere que os três usam o componente e que o HTML é o mesmo, mudando só o item atual.
- Sem migration, sem rota nova, sem mudança de permissão.
