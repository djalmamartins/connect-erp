# Design system próprio Connect ERP
Tokens semânticos de cor, tipografia, espaçamento, elevação, borda, responsividade, foco e estados.
Componentes: AppShell, Sidebar, Breadcrumb, PageHeader, DataTable, FilterPanel, Pagination, FormField, Select, DatePicker, CurrencyInput, Modal, ConfirmDialog, Toast, EmptyState, LoadingState, ErrorState, PermissionGuard, ExportMenu, AuditTimeline.
Estados obrigatórios: carregando, vazio, erro, acesso negado, sucesso e dados parciais.
Acessibilidade: navegação por teclado, labels, foco visível, contraste, feedback de validação e responsividade.
Uma única fonte de tokens e componentes. Não copiar cores, logos, ícones proprietários nem HTML do produto de referência.

## Formulários do ERP (09/11/2026)

- Inputs e selects têm a mesma altura (`--erp-control-height`, 40 px). A seta do select é própria, a 12 px da borda direita.
- Os campos de `.erp-form__grid` são alinhados pelo topo. Assim, um texto de ajuda (`.erp-field__hint`) ou uma mensagem de erro embaixo de um campo não desloca nem estica os vizinhos.
- Quando um rótulo quebra em duas linhas, o `erp.js` dá aos rótulos daquela linha a altura do maior. O cálculo usa um ResizeObserver, então vale também em modais e campos que aparecem depois.
- Teste: `tests/legacy/Unit/ErpFormLayoutTest.php`.

## Campo padrão e espaçamento em todo o sistema (20/11/2026, ADR 0628)

Vale para ERP, Studio, Help Desk, aplicativo web do morador e Central de Ajuda.

- **Campo** = rótulo em cima, o campo, e embaixo o espaço do texto de ajuda ou do erro. Classes:
  ERP `.erp-field` (`label`, controle, `.erp-field__hint` / `.erp-field__error`); Studio e Help Desk
  `<label>Texto<input><small>ajuda</small></label>` ou `.studio-field`; app `.app-field`; Central
  `.hc-form label > span`.
- **Linha de campos** (`.erp-form__grid`, `.studio-fields-two/-three`, `.studio-grid`,
  `.studio-form-grid`, `.app-field-row`, `.hc-form__row`): grade com `align-items: start`, e o
  campo com `align-content: start`. A ajuda embaixo de um campo nunca empurra o vizinho.
- **Rótulo em duas linhas**: o ERP (`erp.js`) e o Studio (`scripts.js`) igualam a altura dos
  rótulos da mesma linha.
- **Campo sem largura** em `.erp-form__grid` (sem `erp-col-*`) ocupa meia linha; no celular, a
  linha inteira.
- **Blocos**: filtro, aviso, tabela e barra de ações têm 16 px entre si no ERP; no Studio os
  painéis das Configurações têm 18 px.
- **Barra de Salvar** (Configurações e Permissões do Studio e do Help Desk): fica no fim do
  formulário, no fluxo da página. Não é mais grudenta (não para no meio da tela por cima de outro
  bloco).
- **Botão flutuante "Ajuda"**: o conteúdo do Studio e do Help Desk tem 104 px livres no pé (88 px no
  celular) para o botão não cobrir o último botão da página.
- **Celular (ERP)**: a trilha "Início / …" some da barra do topo; o título da página já diz onde se está.
- Conferência: varredura com Playwright em todas as telas (desktop 1366 px e celular 390 px)
  procurando campos da mesma linha em alturas diferentes, caixas coladas e barras grudentas.
  Teste fixo: `tests/legacy/Unit/FormFieldStandardTest.php`.
