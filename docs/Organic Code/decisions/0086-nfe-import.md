# 0086 — Importação de NF-e XML (AP-141): contas a pagar depois da prévia, sem SEFAZ

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

Faltava a importação de NF-e em XML (AP-141). A coordenação pediu leitura segura do XML (sem
entidades externas nem DOCTYPE, com limite de tamanho), conta a pagar só depois da prévia e da
aplicação, como nas outras importações (ADR 0058), nenhuma consulta à SEFAZ ou a outro serviço
externo, e a chave de acesso única por condomínio.

## Decisão

- **Leitura** (`Imports\NfeParser`):
  - até 1 MB; `<!DOCTYPE` e `<!ENTITY` recusados antes do parser, que roda com `LIBXML_NONET` e sem
    expandir entidades (sem XXE);
  - lê a chave de acesso (`Id="NFe…"`, 44 dígitos, com o **dígito verificador conferido** pelo
    módulo 11), emitente, destinatário, número, série, emissão, valor total (`vNF`), valor líquido
    (`cobr/fat/vLiq`) e as duplicatas (`cobr/dup`, até 120);
  - **a assinatura digital não é conferida**, porque exigiria a cadeia de certificados ou a SEFAZ. A
    nota só vira lançamento depois da prévia e da aplicação.
- **Prévia** (tipo `nfe` em `POST /api/v1/imports/preview`, com `chart_account_id` e, sem duplicata,
  `due_on`), com regras conservadoras:
  - **o destinatário precisa ser o CNPJ do condomínio**. Condomínio sem CNPJ cadastrado recusa;
  - **o emitente precisa ser fornecedor já cadastrado** (pelo CNPJ/CPF). Não é criado sozinho;
  - a chave não pode estar lançada no condomínio;
  - as duplicatas precisam somar o líquido, ou o total quando não há líquido. Sem duplicata, vale o
    vencimento informado;
  - a competência é o mês da emissão e não pode estar fechada;
  - a conta de despesa (analítica, ativa) e o vencimento ficam nas opções da importação
    (`options_json`) e valem na aplicação.
- **Aplicação:** relê e revalida o arquivo guardado. Gera **uma conta a pagar por duplicata**
  ("NF-e 123 Fornecedor — 1/2", documento "NF 123/1"), com o fornecedor e o `import_id`, e grava a
  NF-e em `erp_nfe_documents`. **A chave de acesso é única por condomínio** (`UNIQUE`), o que barra a
  segunda importação da mesma nota mesmo vinda em outro arquivo.
- **Migration 20261108_0541:** o tipo `nfe`, o `options_json` e a tabela `erp_nfe_documents`.
- **Permissão:** `erp.imports.manage` e `erp.entries.manage`, como as demais importações de lançamento.

## Consequências

- A tela (layout, PR #86) é a mesma das importações, com a escolha da conta de despesa; o
  `Panel::pagePermissions` mapeia AP-141 para `erp.imports.view`.
- Retenções (ISS, IRRF, INSS) da nota não são lançadas aqui; a alíquota de referência fica no cadastro
  de impostos (AP-164, ADR 0085).
