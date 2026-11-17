# 0337 — Revisão de segurança: CSRF em todo POST de página, com teste que cobre as rotas futuras

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7 ("CSRF nas rotas novas desde 11/11"); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0150 (varredura da API), ADR 0326 (limite por IP)

## Contexto

A API (`/api/v1`) já tinha varredura estática (ApiGuardCoverageTest) e confere o `X-CSRF-Token` no middleware. As páginas
(ERP, site, Central de Ajuda, app web do morador, Help Desk, Studio) dependiam de cada controller lembrar do `csrf_verify`.

## Decisão

1. `WebCsrfCoverageTest` lê os arquivos de rotas de todos os ambientes, acompanha o `namespace` de cada trecho e, para cada
   `$route->post(..., "Controller:método")`, exige que a ação chame `csrf_verify` (ou o `csrfMatches` da verificação de
   documento) direto ou por um método do próprio controller, em qualquer profundidade. Inclui o laço dos cadastros do ERP.
2. Exceções documentadas no teste: duas buscas por POST que só redirecionam para um GET (`Web:articlesSearch` no site e
   `Studio:posts` no Studio). Não gravam nada.

## Resultado da revisão (17/11/2026)

- Mais de 100 POSTs de página conferidos: nenhum sem CSRF. Nada precisou ser corrigido.
- Teste de mutação: tirar o `csrf_verify` do fechamento mensal faz o teste apontar as duas rotas.

## Consequências

- Rota nova sem CSRF quebra o CI. Uma busca nova por POST precisa entrar na lista de exceções com justificativa.
- Continua pendente na §3.7: acesso cruzado entre condomínios e IDOR nas rotas novas, upload e XSS.
