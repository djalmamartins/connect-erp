# ADR 0363 — Aviso de envio acima do post_max_size

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0098 (limites de upload), ADR 0362 (limites do PHP no deploy-check)

## Contexto

Quando o corpo do pedido passa do `post_max_size`, o PHP descarta tudo antes do sistema: `$_POST` e `$_FILES` chegam
vazios, inclusive o token CSRF. No ERP a tela respondia "Sessão expirada. Tente de novo." ou "Envie um arquivo", e a
pessoa repetia o mesmo envio sem entender. O Connect Talk já tratava esse caso no próprio front controller; o ERP não.

## Decisão

- `Source\Support\OversizedPost::handle()` roda no `index.php` depois da sessão e antes das rotas (e depois do desvio do
  Talk, que tem o tratamento próprio).
- Detecta: POST/PUT/PATCH com `Content-Length` acima do `post_max_size` e `$_POST`/`$_FILES` vazios (`post_max_size` 0
  não tem limite).
- API (`/api/...`), `Accept: application/json` ou `X-Requested-With: XMLHttpRequest`: 413 com
  `{"error":{"code":"payload_too_large","message":"O envio passou do limite de N MB..."}}`.
- Formulário comum: aviso na sessão (flash) e 303 para o `Referer` **do mesmo host**; sem ele, para a própria página.
  Nunca para outro site.
- Log `post_too_large` com o tamanho e o limite.

## Testes

- `OversizedPostTest`: detecção (limite exato passa, corpo recebido passa, GET, sem limite, sem Content-Length); mensagem
  em MB; JSON para API e scripts; volta só para o mesmo host; chamada no `index.php` antes das rotas e depois da sessão;
  servidor embutido do PHP com `post_max_size=1K` descartando o corpo de verdade.
- Conferido à mão com o `index.php` completo no servidor embutido (`post_max_size=1K`): 413 em `/api/v1/documents`, 303
  de volta para o Referer em `/disco`, pedido pequeno segue para as rotas (401 sem login).

## Riscos

- O webhook dos bancos com corpo acima do `post_max_size` recebe 413 (o banco reenvia); os corpos reais têm poucos KB.
