# 0636 — Menu lateral mantém a rolagem entre páginas (ERP, Studio e Help Desk)

- **Status:** aceito
- **Data:** 2026-11-22
- **Relacionados:** 0627 (alternador de ambientes único), 0026 (moldura do ERP no Studio e no Help Desk).

## Contexto

O Djalma relatou que o menu lateral do ERP é longo: ao clicar num item lá embaixo (por exemplo, Relatórios >
Rateio), a página recarrega e o menu volta para o topo. Para ir ao próximo item ele precisa rolar e procurar de
novo. O grupo do item ativo já abria sozinho (o servidor marca `open` no `<details>` que contém a página atual),
mas a rolagem do menu se perdia.

## Decisão

- Ao clicar num link do menu (e no `pagehide`, para voltar/avançar e outros jeitos de sair), a posição de rolagem
  do menu é guardada no `sessionStorage` da aba. Na página seguinte o menu volta para a mesma posição.
- Depois de restaurar, se o item ativo (`aria-current="page"`) não estiver à vista, o menu rola só o necessário
  para deixá-lo no meio da área do menu. A conta usa `scrollTop` do próprio menu, sem `scrollIntoView`, para não
  mexer na rolagem da página.
- O grupo aberto continua vindo do servidor (grupo da página atual) e o acordeão segue fechando os outros, então
  o desenho do menu na página nova é o mesmo de quando se clicou.
- ERP: função `keepNavPosition` em `assets/js/erp.js` (chave `erp.sidebar.scroll`), publicada no `erp.min.js`.
- Studio e Help Desk: o mesmo comportamento no script do `layouts/studio.php`, com uma chave por ambiente
  (`studio-menu-scroll-studio` e `studio-menu-scroll-helpdesk`).
- `sessionStorage` vale só para a aba: outra aba ou um novo login começam do topo. Em modo privado sem
  armazenamento, o menu apenas mostra o item ativo.

## Consequências

- Não grava dados no servidor nem dado pessoal; nada muda em permissão, API ou banco.
- Sem página nova: nenhum artigo novo na Central de Ajuda.
- Teste unitário `SidebarScrollPositionTest` confere o comportamento nos arquivos do ERP, no bundle e no layout do
  Studio. O comportamento no navegador foi conferido com Playwright no HTML do menu.
