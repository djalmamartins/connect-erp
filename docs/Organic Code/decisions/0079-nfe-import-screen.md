# 0079 — Tela da importação de NF-e em XML (AP-141)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: ADR 0086 (ciclo 35 do Avançar)

## Contexto

O ciclo 35 do Avançar (ADR 0086, migration 0541) acrescenta o tipo `nfe` às importações. A nota é
lida com segurança, e cada duplicata vira uma conta a pagar. Sem a página mapeada em
`Panel::pagePermissions`, o `ErpImportScreensTest` falha assim que o tipo existe. Por isso a tela
entra antes do ciclo.

## Decisão

- **A mesma tela das outras importações** (`pages/imports/import`, ADR 0074). O AP-141 pede
  `erp.imports.view`. Enviar exige `erp.imports.manage` e a permissão do tipo (`erp.entries.manage`).
- **Conta de despesa obrigatória.** O controlador oferece as contas de despesa analíticas e ativas do
  condomínio da barra superior, as mesmas de Contas a pagar. Sem nenhuma conta, a tela avisa e não
  mostra o envio.
- **Vencimento opcional** (`due_on`): só vale para a nota sem duplicata. Quando o XML traz duplicatas,
  vale o vencimento de cada uma.
- **Contrato da prévia** (`POST /api/v1/imports/preview`, multipart): `kind=nfe`, `condominium_id`,
  `chart_account_id`, `due_on` (quando preenchido) e `file`. O apply não muda, porque a conta e o
  vencimento ficam gravados na prévia (`options_json`).
- **Limite de 1 MB na tela** (`ImportScreens::maxBytes`), o mesmo do leitor (`NfeParser::MAX_BYTES`).
  As outras importações seguem com 2 MB.
- **Prévia:** um resumo com o número e a série da nota, o emitente, a data, o total, o valor líquido
  e as duplicatas, e uma linha por duplicata.
- Arquivo sem colunas (OFX e NF-e) não tem modelo CSV.

## Consequências

- A tela só passa a valer quando `Imports::KINDS['nfe']` existir. Antes disso, o AP-141 segue com a
  demonstração.
- O smoke cobre a tela, mas só quando o tipo existe. Ela foi conferida com o tipo acrescentado
  localmente, sem commit: as duas verificações de telas ficaram sem falhas. O `ImportsTest` do
  Avançar precisa listar o AP-141, e isso entra no próprio ciclo 35.
- Nada foi testado contra MariaDB real.
