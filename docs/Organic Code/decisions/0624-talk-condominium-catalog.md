# 0624 — Dados dos condomínios para o Connect Talk (escopo condominiums.read)

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0623 (token com escopos), 0308 (integração com o Connect Talk), 0359 (Talk no Raspberry Pi),
  0030 (condomínio como contexto), 0350 (registro das operações de tratamento).
- Numeração: faixa avulsa 0600–0799; conferidos o índice e os branches em 20/11 antes de usar.

## Contexto
Etapa 2 da API do ERP para o Connect Talk (proposta no Claude Doc "API do ERP para o Connect Talk — proposta"). O
escopo `condominiums.read` já existia desde a ADR 0623, mas nenhuma rota usava. Para responder no WhatsApp "qual o
endereço?", "o salão tem taxa?", "qual o seu apartamento?" e "tem algum aviso?", o Talk precisa dos dados do
condomínio, e só deles: nada de pessoa.

## Decisão
Quatro rotas GET novas em `/api/v1/talk`, todas com `TalkApiAuthenticated` e o escopo `condominiums.read` (sem ele,
403 `insufficient_scope`). O serviço é `Source\Services\Talk\TalkCatalog`.

| Rota | O que devolve |
|---|---|
| `GET /talk/condominiums?page=` | id, nome, bairro, cidade e UF dos condomínios **ativos** do escopo do usuário de integração; 50 por página |
| `GET /talk/condominiums/{id}` | endereço (com `short`, ex. "Av. Brasil, 120 · Campinas/SP") e áreas comuns ativas que aceitam reserva (`free` ou `approval`): capacidade, taxa em centavos, horário e regras |
| `GET /talk/condominiums/{id}/units?page=` | unidades ativas: id, bloco, número, rótulo e tipo; 200 por página |
| `GET /talk/condominiums/{id}/announcements?page=` | comunicados `general`, ativos, de audiência "todos" e já liberados (`send_at <= NOW()`), mais novos primeiro, texto sem HTML; 50 por página |

- Listas: `{data: [...], meta: {page, per_page, total}}` (`TalkPageMeta` no OpenAPI). Página inválida volta para a 1.
- Condomínio fora do escopo, inativo ou que não existe: o mesmo 404 `not_found`, sem dizer qual (não vaza que existe).
- **Fica de fora de propósito (LGPD, coletar só o necessário):** CNPJ, dados bancários, síndico, moradores e donos das
  unidades, comunicados enviados só a algumas unidades (são por morador, etapa 3), comunicados de cobrança e áreas
  bloqueadas ou sem reserva. Um teste de unidade lê as consultas do serviço e falha se aparecer coluna de pessoa.

## Consequências
- O Talk pode montar o menu de condomínios, conferir a unidade que a pessoa digitou e responder dúvidas de áreas e avisos
  sem dado pessoal. Os tokens antigos (escopos legados) não ganham essas rotas sozinhos: é preciso marcar o escopo na tela.
- Sem migration. Etapa 3 (por morador, `residents.read`) e etapa 4 (respostas da equipe para o Talk) seguem na proposta.

## Testes
`tests/legacy/Unit/TalkCatalogRoutesTest.php` (paginação, rotas, OpenAPI, consultas sem pessoa) e
`tests/legacy/Integration/TalkCatalogTest.php` (escopo e 404, inativo, unidades sem morador, áreas e comunicados filtrados,
403 sem o escopo).
