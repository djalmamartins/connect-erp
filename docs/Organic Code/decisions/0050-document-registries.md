# 0050 — Avisos de recebimento, cópias de cheques, empenhos e malotes

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

A seção Documentos do catálogo tem quatro listas operacionais: Avisos de Recebimento (AP-126),
Cópias de Cheques (AP-127), Empenhos (AP-128) e Malotes (AP-129). Três delas pedem anexo. O Disco
Virtual (ADR 0047) já guarda arquivos fora da pasta pública, com download por permissão e escopo.
As fichas ainda são scaffold; os campos seguem `page-specs.php` e são PROPOSTOS.

## Decisão

- **Quatro cadastros no motor único** (ADR 0014), no grupo **Documentos**. Todos por condomínio, com
  exclusão lógica, CSRF nas telas, auditoria e API REST gerada. Migration 0529, com tabelas e
  permissões `erp.<cadastro>.view|manage`.
  - **Avisos de recebimento (`/delivery-notices`):**
    - destinatário, unidade opcional, código de rastreio, assunto, envio e entrega (não antes do
      envio);
    - situação: em trânsito, entregue ou devolvido;
    - comprovante opcional.
  - **Cópias de cheques (`/check-copies`):**
    - conta bancária do condomínio, número, data, favorecido, valor e lançamento opcional;
    - **imagem obrigatória**;
    - o mesmo número na mesma conta não repete;
    - situação: emitido ou cancelado.
  - **Empenhos (`/commitments`):**
    - fornecedor, descrição, valor e data;
    - liquidação com data (não antes do empenho) e o lançamento que liquidou;
    - situação: empenhado, liquidado ou cancelado.
  - **Malotes (`/pouches`):**
    - envio, portador, quantidade e lista dos documentos;
    - recebimento e conferência (conferir exige o recebimento, e a data não pode ser antes dele);
    - situação: enviado, recebido ou conferido.
- **Anexo pelo Disco Virtual:** a chave nova `attachment` do motor marca o campo `document_id`. O
  `RegistryService` confere que o arquivo existe, não foi excluído e é do mesmo condomínio do
  registro. O arquivo continua guardado e baixado só pela API do Disco Virtual, com permissão e
  escopo.
- **Vínculos:** um lançamento apontado por cheque ou empenho não pode ser excluído (campo `ref`,
  ADR 0020).

## Consequências

- Na tela, o campo do anexo é o número do arquivo no Disco Virtual. A frente de layout pode trocá-lo
  por um seletor ou por um envio no próprio formulário, usando a API do Disco Virtual.
- Com isso, a seção Documentos do catálogo está coberta no backend: AP-122 (ADR 0047), AP-123 a 125 e
  AP-130 (ADR 0044), e AP-126 a 129 (este ADR).
