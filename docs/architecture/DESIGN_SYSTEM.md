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
