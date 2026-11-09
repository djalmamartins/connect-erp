# 0046 — Telas dos documentos da unidade (AP-123, AP-124, AP-125 e AP-130)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0044 trouxe os quatro documentos da unidade (certidão de débitos, declaração de
inadimplência, comprovante de quitação e carta de anuência), gravados com código de verificação, e a
API `/api/v1/unit-documents`. As quatro páginas do catálogo mostravam só a prévia de demonstração.

## Decisão

- Uma tela compartilhada (`pages/documents/unit-document.php`) para as quatro páginas;
  `Panel::UNIT_DOCUMENT_PAGES` liga cada página ao tipo do documento. `Panel::unitDocumentData` exige
  `erp.unit_documents.view`; sem permissão ou sem banco, segue a demonstração (`Panel::ownTemplate`).
- **Emitir** (só com `erp.unit_documents.manage`): unidade (agrupada por condomínio, só as do
  condomínio da barra superior quando há um escolhido; `Panel::documentUnits`, regra pura), data de
  referência e o campo próprio do tipo: competência final na quitação, finalidade na anuência. Emitido,
  abre o documento. As recusas do serviço (409, por exemplo quitação com conta em aberto) aparecem
  como aviso.
- **Documento** (`?documento=ID`): folha com emissor, número, condomínio, unidade, texto do tipo,
  contas vencidas com correção, multa e juros, contas a vencer e o código de verificação em quatro
  grupos. "Imprimir" usa a impressão do navegador, com estilo de impressão que esconde a moldura.
  Cancelado mostra o motivo e a marca "CANCELADO".
- **Conferir autenticidade**: o código digitado (com ou sem hífens) abre o original na página do tipo
  certo. Segue o ADR 0044: exige login e fica no escopo do usuário.
- **Emitidos**: lista do tipo da página no escopo, com resultado, total, código, situação, abrir e
  cancelar com motivo (5 a 255 caracteres).

## Consequências e lacunas

- Sem PDF: a impressão é a do navegador ("Salvar como PDF" funciona). Gerar PDF no servidor fica para
  quando houver modelo de documento com assinatura.
- A verificação pública por terceiros continua pendente de decisão do Djalma (issue #46).
- Testado sem MariaDB: render-smoke com dados simulados, teste unitário de contrato e chamadas da API
  conferidas com mocks no navegador.
