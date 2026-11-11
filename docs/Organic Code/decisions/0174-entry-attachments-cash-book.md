# ADR 0174 — Anexos dos lançamentos (nota fiscal e comprovante) e livro caixa mensal (CN-007)

- Situação: aceita (11/11/2026)
- Contexto: pedido do Djalma em 11/11/2026 (áudio): "quando eu faço um lançamento, eu tenho como anexar nota
  fiscal e comprovante de pagamento? Se não tiver, acrescenta isso, porque a gente precisa fazer o livro caixa
  mensal e eu queria que ele já saísse todo pronto, todo editado". Antes: o lançamento (AP-020/022/023) só tinha
  o campo de texto "Nº do documento"; anexo existia só em cadastros de documentos (ADR 0050, por número do
  arquivo do Disco Virtual). Não havia livro caixa; o mais próximo era o extrato de uma conta (AP-056).

## Decisão

- **Anexos do lançamento** (`erp_entry_attachments`, migration 0711): vínculo entre o lançamento e um arquivo do
  Disco Virtual (ADR 0047), com o tipo `nota_fiscal`, `comprovante`, `recibo` ou `outro`. O envio passa pelas
  mesmas regras do Disco Virtual (tipo pelo conteúdo, 20 MB, pasta privada, antivírus do ADR 0098). Nota fiscal
  cai na pasta "Notas fiscais"; comprovante e recibo na pasta nova "Comprovantes"; outro em "Outros".
  - O mesmo arquivo (sha256) já na pasta do condomínio é reaproveitado (uma nota para duas parcelas); no mesmo
    lançamento, recusado. No máximo 20 anexos por lançamento.
  - Anexar vale em competência fechada (o comprovante chega depois e não muda valor). Remover não: o livro já
    entregue precisa continuar batendo. Remover é exclusão lógica do vínculo; o arquivo fica no Disco Virtual.
  - Permissões: as do lançamento (`erp.entries.view` para ver e baixar, `erp.entries.manage` para anexar e
    remover). Escopo pelo condomínio do lançamento (fora = 404). Tudo auditado.
  - Tela: cartão "Notas e comprovantes" na edição de Lançamentos, Contas a pagar e Contas a receber, com envio
    multipart e CSRF (`EntryAttachmentScreens`). O XML da NF-e passa a ser aceito no Disco Virtual (só download,
    nosniff e CSP sandbox).
- **Livro caixa mensal** (CN-007, `/prestacao-de-contas/livro-caixa`, `Source\Services\Erp\CashBook`), regime de
  caixa, de um condomínio: saldo anterior = soma das contas bancárias no dia 1 (mesmo cálculo da prestação de
  contas, `FinancialReports::statement`); cada lançamento válido pago no mês com saldo corrido; anexos numerados
  A1, A2... na ordem do livro; resumo por conta; contas bancárias com transferências; despesas pagas sem nota,
  comprovante nem recibo; conferência com os bancos (diferença exposta, nunca escondida). Competência aberta sai
  como **Prévia**. Transferências entre contas do condomínio não viram linha (somam zero no caixa).
  - **PDF pronto** (`CashBookDocument`, dompdf sem acesso remoto): cabeçalho com a marca da Connect, dados do
    condomínio (razão social, CNPJ, endereço), resumo, movimento, resumo por conta, pendências, assinaturas
    (síndico, conselho fiscal, administradora), índice dos anexos e cada anexo de imagem numa página (reduzido a
    1600 px). Rodapé com página X de Y.
  - **Pacote ZIP**: o PDF e todos os anexos numerados (`anexos/A01-nota-fiscal-...pdf`), até 200 MB.
  - Downloads auditados (`erp_reports`, report `cash-book`) e limitados a 30 a cada 10 minutos.
- **API REST junto (V3):** `GET /api/v1/reports/cash-book?condominium_id=&competence=`,
  `GET|POST /api/v1/entries/{id}/attachments`, `GET /api/v1/entries/{id}/attachments/{attachment}/download` e
  `DELETE /api/v1/entries/{id}/attachments/{attachment}`, no OpenAPI. Nunca expõe o caminho do arquivo.

## Alternativas descartadas

- Campo `document_id` no próprio lançamento (como no ADR 0050): só um arquivo por lançamento, e a nota e o
  comprovante são dois documentos.
- Juntar os anexos em PDF dentro do livro: o dompdf não junta PDFs, e bibliotecas de junção gratuitas falham com
  PDFs comprimidos (PDF 1.5+), comuns em DANFE e comprovantes de banco. Os PDFs vão no pacote, citados no índice.

## Consequências e lacunas

- O backup precisa continuar levando `storage/private/documents` (já previsto no ADR 0047).
- PENDENTE: juntar os PDFs anexados dentro do PDF do livro (depende de ferramenta no servidor, por exemplo
  Ghostscript ou qpdf, a confirmar no ambiente de produção).
- PENDENTE: termo de abertura e encerramento numerados por exercício, se o Djalma quiser o modelo de livro
  encadernado; hoje cada mês sai como documento próprio.
