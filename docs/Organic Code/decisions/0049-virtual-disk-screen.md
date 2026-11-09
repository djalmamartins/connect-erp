# 0049 — Tela do Disco Virtual (AP-122)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0047 trouxe o Disco Virtual: arquivos do condomínio por pasta, guardados fora da pasta
pública, tipo conferido pelo conteúdo, download só com permissão e escopo, e a API
`/api/v1/documents`. A página AP-122 mostrava só a demonstração.

## Decisão

- `Panel::virtualDiskData` (com `erp.documents.view`) lista os arquivos do condomínio da barra
  superior, por pasta (`?pasta=`), com busca (`?q=`) e páginas de 50. Sem permissão ou sem banco,
  segue a demonstração.
- **Pastas** como abas (Todas, Atas, Contratos, Plantas, Notas fiscais, Outros).
- **Enviar** (com `erp.documents.manage`): condomínio (fixo quando há um na barra superior), pasta,
  título opcional e o arquivo, escolhido ou arrastado. O navegador avisa antes do envio sobre
  extensão fora da lista e arquivo acima de 20 MB (a mesma lista do servidor, conferida por teste);
  quem decide é o servidor, que confere o tipo pelo conteúdo. O envio é multipart por
  `XMLHttpRequest`, com barra de progresso e o CSRF no cabeçalho.
- **Baixar** é um link para `/api/v1/documents/{id}/download` (GET com a sessão; o servidor manda como
  anexo). **Excluir** pede confirmação e usa `DELETE` (exclusão lógica, auditada).

## Consequências e lacunas

- Um arquivo por envio.
- Sem pré-visualização no navegador: o download é sempre anexo, por segurança (ADR 0047).
- Testado sem MariaDB: render-smoke com dados simulados, teste unitário e chamadas conferidas com
  mocks no navegador (multipart com CSRF, recusa de extensão, exclusão).
