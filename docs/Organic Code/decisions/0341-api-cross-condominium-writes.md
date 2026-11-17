# 0341 — Revisão de IDOR: ações que alteram e downloads da API

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7; continuação do ADR 0340; Djalma, 16/11/2026: "tome as melhores decisões"

## Contexto

O ADR 0340 cobriu as 27 rotas GET com `{id}`. Faltavam as que alteram (POST/PUT/PATCH/DELETE) e os downloads, onde um
vazamento é pior: cancelar, aplicar, apagar ou baixar um registro de outro condomínio.

## Decisão

`ApiCrossCondominiumIntegrationTest::testOutOfScopeRecordsCannotBeChanged` chama 52 ações do ERP (fatura, boleto, evento
bancário, prestação de contas, aplicação financeira, importações, acordo, documento da unidade, troca de ocupante, honorários e
NFS-e, Disco Virtual, anexos de lançamento, ocorrência, reserva, manutenção, cobrança extra, transferência, conciliação,
aviso, assembleia, vínculo de pessoa, processo jurídico, vistoria e chamado):

- o corpo vai por um `php://input` do próprio teste (`ScopeTestInput`), com campos genéricos que passam da validação e chegam
  ao carregamento do registro;
- quem só vê o Alpha precisa receber 403 ou 404;
- quem vê todos os condomínios não pode receber 401/403/404 (controle positivo), menos em 4 rotas listadas em `NO_CONTROL`
  com o motivo (feriado é por cidade, troca de ocupante precisa de pessoa real, os dois downloads precisam do arquivo no
  disco); nelas a trava foi conferida no código.

## Resultado

As 52 ações recusam o Beta (404). Para conferir que o teste pega a falha, a trava de `BankReturns::resolve` foi retirada por
um instante: o teste apontou `POST /bank-events/{id}/resolve => 200`. A trava foi devolvida.

## Fora desta fatia

Rotas do app do morador (`/resident/...`, filtradas pela unidade do vínculo), parceiros (`/partner-*`, por imobiliária),
catálogo de onboarding (global) e Talk (testado no `TalkIntegrationTest`).
