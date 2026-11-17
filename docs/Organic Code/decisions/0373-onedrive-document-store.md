# ADR 0373 — Armazenamento no OneDrive pelo Microsoft Graph (passo 4, ainda desligado)

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADRs 0370–0372 (DocumentStore no Disco Virtual, fotos de vistoria e foto do morador), ROADMAP_V1 §3.5

## Contexto

Com os arquivos atrás do `DocumentStore`, falta a implementação do OneDrive empresarial da Connect. A conta Microsoft 365
ainda não existe, mas o código pode ser escrito e testado contra um Graph falso agora.

## Decisão

`Source\Support\Storage\OneDriveDocumentStore`:

- **Acesso:** aplicativo registrado no Entra ID com *client credentials* (`scope=https://graph.microsoft.com/.default`),
  permissão de aplicativo só no drive da Connect. O token é pedido uma vez e reaproveitado até 2 minutos antes de vencer.
- **Onde:** `{pasta}/{caminho relativo}` no drive configurado. O caminho é o mesmo do disco local, então migrar é copiar a
  árvore. Cada parte do caminho é codificada; caminho com `..`, absoluto, `\` ou byte nulo nem chega ao Graph.
- **Envio:** até 4 MB num `PUT .../content`; acima, sessão de envio (`createUploadSession`) em partes de 3.200 KiB
  (múltiplo de 320 KiB, como o Graph exige). O endereço da sessão já vem autorizado e não recebe o token.
- **Leitura:** `path()` devolve uma cópia local num cache (gravada em `.part` e renomeada). O Graph responde 302 para um
  endereço temporário de download; o store só segue HTTPS e não manda o token para esse outro host.
- **Falhas:** sem resposta ou autorização expirada, `put()` devolve false (o envio mostra erro) e `path()` devolve null
  (o arquivo fica indisponível); nada lança exceção para a tela e tudo vai para o log (`onedrive_put|path|delete`).
- Transporte HTTP próprio (`GraphTransport`/`CurlGraphTransport`): só HTTPS, sem seguir redirecionamento sozinho.

**Ainda desligado:** nenhum serviço usa o OneDrive até o próximo passo ligar a escolha pelo `.env` (chaves do Entra ID,
drive e pasta) e a conta da Connect existir. Pendências do Djalma: conta Microsoft 365 Business no domínio, registro do
aplicativo (o roteiro vai junto do passo de configuração) e a limpeza do cache local (o cache cresce com o uso).

## Testes

`OneDriveDocumentStoreTest` com Graph falso: endereço codificado, envio pequeno, leitura com 302 e cache, um token só,
token nunca no endereço de download nem na sessão de envio, envio grande em duas partes com `Content-Range` certo,
exclusão, autorização recusada sem exceção, caminhos ruins sem chamada ao Graph, só redirecionamento HTTPS.
