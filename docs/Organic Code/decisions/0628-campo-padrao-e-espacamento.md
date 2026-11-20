# ADR 0628 — Campo padrão e espaçamento em todos os formulários

- Status: Aceito
- Data: 20/11/2026
- Pedido: Djalma, 20/11/2026, com prints de Studio > Configurações > E-mail ("e-mail do remetente,
  aí embaixo ele tem um aviso, e aí do outro lado o formulário fica quebrado ... cria um padrão para
  todos os formulários ... passa em todo o sistema") e da barra "As alterações serão aplicadas ao
  site após salvar" colada no bloco de baixo e andando junto com a rolagem.

## Contexto

CONFIRMADO no repositório (main 4da8dde):

- No Studio e no Help Desk o campo é `<label>` com `display: grid`. Na grade de duas ou três
  colunas o `<label>` estica até a altura do vizinho mais alto e as linhas internas dele (rótulo e
  campo) crescem juntas. Quando um campo tem texto de ajuda embaixo, o campo do lado desce
  (10 a 17 px nas abas E-mail e Aparência).
- O ERP já tinha o padrão certo desde 09/11 (`.erp-field` com `align-content: start`, rótulos da
  mesma linha com a mesma altura pelo `erp.js`), mas um campo sem `erp-col-*` ficava com 1/12 da
  linha (Configurações > Dados pessoais: "CPF ou CNPJ" quebrava letra a letra).
- `.studio-settings-save` e `.studio-access-save` eram `position: sticky; bottom`. A barra
  acompanhava a rolagem e parava no fim do formulário, colada no bloco seguinte (teste de e-mail).
  O botão flutuante "Ajuda" ainda cobria o "Salvar configurações".
- Varredura (Playwright, 1366 px e 390 px, ~690 telas: ERP, Studio, Help Desk, app web e site)
  achou também filtro colado na tabela (Faturas, Lançar desconto), aviso colado na tabela
  (Integrações) e tabela colada na barra de ações (Fração ideal).

## Decisão

1. Um padrão de campo para todo o sistema: rótulo em cima, campo, e o espaço do texto de ajuda ou
   de erro embaixo. Linhas de campos alinham pelo topo (`align-items: start`) e o campo pelo topo
   (`align-content: start`). Detalhes e classes em `docs/architecture/DESIGN_SYSTEM.md`.
2. O Studio ganha o mesmo ajuste de rótulos em duas linhas que o ERP já tinha (`scripts.js`: o
   texto solto do `<label>` vira `<span class="studio-field-label">` e os rótulos da linha ficam
   com a altura do maior).
3. Barra de Salvar no fim do formulário, no fluxo da página, sem sombra flutuante. Escolhida no
   lugar de uma barra fixa no rodapé porque o Studio e o Help Desk têm o botão "Ajuda" flutuante no
   mesmo canto e a barra fixa cobriria o conteúdo no celular.
4. Espaço padrão entre blocos: 16 px no ERP (filtro, aviso, tabela, barra de ações) e 18 px entre
   os painéis das Configurações do Studio. O conteúdo do Studio reserva 104 px no pé para o botão
   "Ajuda".
5. Campo sem `erp-col-*` no ERP ocupa meia linha (linha inteira no celular).
6. No celular, a trilha do topo do ERP some (ela quebrava em quatro linhas por cima dos botões).

Só CSS e um trecho de JS do Studio. Nenhuma rota, permissão, tabela ou API muda; não há migration.

## Testes

- `tests/legacy/Unit/FormFieldStandardTest.php` trava as regras (campo pelo topo, barra fora do
  sticky, bundles regenerados).
- Varredura antes/depois com prints em `/mnt/project-files/campos-padrao/`.

## Riscos e lacunas

- A varredura entra só nas telas alcançáveis por link com a base de demonstração; telas que só
  abrem com dado específico (ex.: modais de fluxos raros) não foram medidas.
- O texto de ajuda continua opcional: o espaço embaixo do campo só aparece quando há ajuda ou erro,
  então a altura de uma linha pode variar; o que não varia é o alinhamento dos campos.
