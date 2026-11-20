# Alternador de ambientes (ADR 0627)

O mesmo botão e o mesmo painel **Ambientes Connect** no ERP, no Studio e no Help Desk.

- Marcação: `Source\Support\EnvironmentSwitcher::render($user, 'erp'|'studio'|'helpdesk', 'Linha pequena', 'Nome do sistema')`.
- Quem vê cada item e para onde ele leva: `Source\Services\Environments` (ADR 0034). Não repetir a lista em outro lugar.
- `envswitch.css` — desenho do botão (sidebar escura) e do painel branco; a cor do item atual vem do `data-environment`
  (ERP verde `#07884c`, Studio roxo `#7c22d4`, Help Desk azul `#2563eb`).
- `envswitch.js` — abre abaixo do botão, fecha no clique fora, no Esc e ao rolar a sidebar; setas andam pelos itens.

Sistema novo com sidebar: carregar os dois arquivos e chamar o `render()`; para outra cor, acrescentar o bloco
`[data-environment="..."]` no CSS.
