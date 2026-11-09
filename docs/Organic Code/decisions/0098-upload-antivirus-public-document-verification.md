# 0098 — Antivírus no upload do Disco Virtual e verificação pública de documentos

Data: 2026-11-09 · Status: aceito (revisado na 27ª rodada) · Decisão do coordenador sobre dois pontos da #46, a pedido do
Djalma ("seguir")

## Contexto

O Disco Virtual (ADR 0047) confere o tipo pelo conteúdo, mas não procura vírus. Os documentos da unidade
(ADRs 0044 e 0057) já têm um código de verificação único, mas só quem tem login e escopo consegue conferir. O
banco, o cartório ou o comprador que recebe uma certidão não tem como saber se ela é autêntica.

## Decisão

### A) Antivírus no upload

- **Interface:** `Antivirus\UploadScanner`, com dois adaptadores.
  - `NullScanner` é o padrão. Não verifica nada e devolve `unscanned`.
  - `ClamdScanner` fala com o clamd pelo comando `INSTREAM`, por socket UNIX ou TCP. O endereço vem de
    `ERP_AV_ENDPOINT` e, sem ele, o adaptador fica desligado. Os timeouts são curtos: 2 s para conectar e
    10 s para ler ou escrever.
- **Política:** `Antivirus\UploadScanPolicy`, que lê o ambiente com `getenv`, sem passar pelo
  `Boot/Settings.php`.
  - `ERP_AV_MODE=off` é o padrão (também quando a variável está vazia): não verifica, e o arquivo entra com
    `scan_status=unscanned`.
  - `ERP_AV_MODE=enforce`: verifica antes de guardar. Arquivo limpo entra com `clean` e `scanned_at`.
    Arquivo infectado é recusado com 422. Antivírus desligado, com erro ou com timeout também recusa o
    envio, com 503 (`scan_unavailable`). É falha fechada.
  - Qualquer outro valor conta como `enforce`, para que um erro de digitação não desligue a verificação.
- **Disco Virtual:** o `VirtualDisk::upload` verifica o arquivo temporário depois das validações e antes de
  movê-lo para a pasta privada. Arquivo recusado não gera registro nem arquivo guardado.
- **Migration 0546:** cria `erp_documents.scan_status` (clean, infected, unscanned ou error, com padrão
  `unscanned`) e `scanned_at`. Os envios anteriores ficam como `unscanned`.
- **Log:** só registra o evento, o condomínio e o sha256. Nunca o nome do arquivo.

### B) Verificação pública: `GET /verificar` e `POST /verificar`

- Reaproveita `erp_unit_documents.verification_code` (16 caracteres sem ambiguidade, de
  `UnitDocuments::newCode`) e `UnitDocuments::normalizeCode`. Não precisou de código novo nem de hash: o
  código já existe e já é impresso nos documentos.
- A resposta só diz que o documento é autêntico, o tipo, a data de emissão e o nome do condomínio. Não mostra
  valores, nomes, unidade, situação de débito nem o arquivo.
- Código inválido, inexistente ou cancelado recebe a mesma mensagem genérica e conta como falha.
- Segue o padrão do `/convite` (ADRs 0092 e 0093):
  - CSRF no POST;
  - limite por IP (`DocumentVerificationThrottle`, escopo `doc_verify`, 20 falhas em 15 minutos), que falha
    fechado; sem IP válido, também recusa;
  - o identificador é o HMAC do IP;
  - o resultado aparece na própria resposta do POST, sem ir para a sessão nem para a URL;
  - cabeçalhos `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private` e
    `X-Robots-Tag: noindex, nofollow`.
- A view nova é `container/web/default/v1/pages/verify.php`. Não mexe no login, no 2FA, em Email, Panel,
  `default.php`, `erp.css` nem Minify.

## Consequências

- Unit:
  - `UploadScannerTest`: um clamd falso confere o protocolo INSTREAM e responde a cada caso. Cobre arquivo
    limpo em vários blocos, EICAR, timeout, conexão recusada, resposta estranha, endereço inválido, modo off
    e enforce com o antivírus fora do ar.
  - `DocumentVerificationTest`: formato do código, mensagem genérica igual para cancelado, inexistente e
    malformado, ausência de dado pessoal, bloqueio, troca de IP, fim da janela e falha fechada. Roda em
    SQLite em memória.
- Integração (MySQL, roda no container): `DocumentVerificationIntegrationTest`, com documento autêntico,
  cancelado, limite e tabela do limite indisponível, mais o `scan_status` gravado e o arquivo recusado que
  não é guardado.
- Para ligar o antivírus em produção, é preciso um clamd acessível e `ERP_AV_MODE=enforce`. Até lá, os
  envios ficam registrados como `unscanned`.
- O link `/verificar` ainda não aparece impresso nos documentos. Isso fica para a tela dos documentos.
