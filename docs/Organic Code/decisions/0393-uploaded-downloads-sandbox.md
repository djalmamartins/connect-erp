# 0393 — Anexos dos chamados e documentos do app com CSP sandbox

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.7 (revisão final de segurança), continuação dos ADRs 0388 a 0391.

## Contexto
O Disco Virtual, os anexos dos lançamentos e o link público da prestação de contas entregam arquivos enviados por pessoas
com `Content-Security-Policy: ... sandbox`: se o navegador abrir o arquivo em vez de baixar, nada nele roda como página do
sistema (sem script, sem acesso à sessão). Dois downloads com o mesmo risco não tinham:

- `GET /api/v1/ticket-attachments/{id}`: anexos dos chamados (enviados pelo site, pelo app e pela equipe);
- os documentos da unidade baixados pelo app do morador (`ResidentApp::sendFile`).

Os dois já saíam como anexo, com `nosniff` e tipo conferido pelo conteúdo; o sandbox é a terceira barreira.

## Decisão
Os dois passam a mandar `Content-Security-Policy: default-src 'none'; sandbox`. O `DownloadCacheHeadersTest` confere
o sandbox nos seis pontos que entregam arquivo enviado por alguém.

PDF aberto na tela (boleto, prestação de contas, vistoria) fica sem sandbox: o leitor de PDF do navegador não abre
com ele, e esses PDFs são gerados pelo próprio sistema.

## Consequências
- Testes: `DownloadCacheHeadersTest::testUploadedFilesAreServedSandboxed` (falha sem a mudança); integração do app do
  morador, dos anexos dos chamados e dos documentos da unidade passando.

## Atualização 22/11/2026 — documentos do morador abrem para ver

Pedido do Djalma: no app do morador, tocar no documento abre o PDF em vez de baixar. Só no app web (`/documentos/{id}`)
e só quando o arquivo é PDF pelo tipo **e** pelo conteúdo (começa com `%PDF-`), `ResidentApp::fileHeaders()` manda
`inline`, `Content-Type: application/pdf` fixo, `nosniff` e `frame-ancestors 'self'`, sem `sandbox` (o leitor de PDF
do navegador não abre com sandbox). Qualquer outro arquivo e a API do celular seguem como anexo com sandbox.
O app do celular baixa no cache e abre no visualizador (iPhone: tela Visualizar; Android: leitor de PDF do aparelho).
Teste: `ResidentDocumentViewHeadersTest`.
