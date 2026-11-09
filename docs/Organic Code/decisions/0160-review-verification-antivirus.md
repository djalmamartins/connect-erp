# 0160 — Revisão de segurança da verificação pública e do antivírus (ADRs 0098 e 0099)

Data: 2026-11-09 · Status: proposto para revisão · Revisão, do ponto de vista de um atacante, dos ciclos 47 e 48

## O que já estava certo

- **Código:** 16 caracteres de um alfabeto de 31 símbolos (~79 bits), gerado com `random_int`. Força bruta
  não é viável.
- **Limite por IP:** 20 falhas em 15 minutos, que falha fechado (tabela fora do ar = recusa). Sem IP válido,
  também recusa.
- **Resposta:** código inexistente e documento cancelado recebem a mesma mensagem e o mesmo status 200. A
  resposta só tem tipo, data e condomínio, sem nenhum dado pessoal.
- **CSRF no POST:** o código vai no corpo, nunca na query, e não entra em log nem na auditoria.
- **Fragmento:** o código só vai depois do `#` no link e no QR. A página não envia o formulário sozinha.
- **Antivírus:**
  - `ClamdScanner` já tinha timeout para conectar e para ler e escrever;
  - resposta estranha vira `error`;
  - o modo enforce recusa com 503 quando o antivírus está fora;
  - `scan_status` só é gravado pelo servidor, no INSERT do upload, a partir do resultado da política.

## O que foi corrigido

1. **Limite global** (`DocumentVerificationThrottle`): cada falha conta também num contador global, que é um
   HMAC fixo. Com 5000 falhas em 15 minutos, somando todos os IPs, todos recebem "muitas tentativas". O teto
   é alto de propósito, para não virar um jeito fácil de derrubar a página para todos.
2. **Mesmo caminho para todo código** (`DocumentVerification::check`): o código malformado também faz a
   consulta, com um valor impossível. Antes ele respondia sem ir ao banco, portanto mais rápido. O código
   devolvido pelo banco é conferido de novo com `hash_equals`, porque o collation do MySQL não diferencia
   maiúsculas.
3. **Cabeçalhos e CSRF** (`DocumentVerify`):
   - cabeçalhos novos: `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`,
     `X-Content-Type-Options: nosniff` e `Pragma: no-cache`;
   - o CSRF passou a ser comparado com `hash_equals` (`csrfMatches`). O `csrf_verify()` do núcleo usa `!=`
     e não foi alterado, porque o login também o usa.
4. **Base do link** (`DocumentVerification::configuredBase`): vem só de `CONF_URL_SSL`. Antes era `url()`,
   que em hosts locais (`*.lab`, `localhost`) monta a URL com o Host da requisição. Um Host forjado punha
   outro domínio no link e no QR impressos. `link()` também passou a recusar `@` na base. Sem base válida,
   o rodapé mostra só o código.
5. **ClamdScanner:**
   - arquivo maior que 25 MB (o `StreamMaxLength` padrão do clamd) vira `error` sem conectar;
   - se o arquivo crescer depois do `filesize()`, também vira `error`;
   - há um prazo total de 30 s, além do timeout de cada operação;
   - só vale a resposta completa, terminada em `\0` e numa linha só. Resposta cortada vira `error`,
     inclusive "FOUND" sem o `\0`.
6. **Download bloqueado** (`VirtualDisk::file`): arquivo com `scan_status` `infected` ou `error` não é
   devolvido, e a API responde 403 (`file_blocked`). Hoje o modo enforce nem grava esses arquivos, mas uma
   verificação posterior ou uma marcação manual pode chegar a esses status.

## Consequências

- `VerificationAntivirusReviewTest` tem um teste por correção. Os testes dos ADRs 0098 e 0099 foram
  ajustados ao contador global e à base configurada.
- No laboratório, o link impresso aponta para `CONF_URL_SSL` (o site configurado), e não para o host
  acessado. Se `CONF_URL_SSL` não for uma URL, o documento sai sem link.
- Sem migration.
