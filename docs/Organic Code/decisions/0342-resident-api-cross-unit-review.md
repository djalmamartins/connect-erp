# 0342 — Revisão de IDOR no app do morador: unidade vizinha e outro condomínio

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7; continuação dos ADRs 0340 e 0341; Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0302 (app web do morador), ADR 0303 (app nativo)

## Contexto

As rotas `/api/v1/resident/...` não usam o escopo de condomínio da equipe: filtram pelo vínculo do morador (condomínio e
unidade). O risco aqui é duplo: ver ou alterar registro de outro condomínio e também da unidade vizinha no mesmo prédio.

## Decisão

`ResidentApiCrossUnitIntegrationTest`: a moradora da unidade 101 do Alpha, com todas as funções do portal ligadas, pede pelo
id 11 rotas do app (lançamento, boleto em PDF, certidão, chamado e resposta, disponibilidade de área, assembleia, documento,
cancelar reserva e visita, bloquear morador da unidade):

- registro da unidade vizinha (102, mesmo condomínio) e do Beta: precisa ser 403/404. Para o que é do condomínio inteiro
  (área comum, assembleia, documento), só o Beta é testado. A disponibilidade de área recusa com 422 ("Escolha uma área comum
  do seu condomínio"), sem devolver nada, e isso conta como recusa;
- controle positivo: o morador dono do registro não recebe 401/403/404, menos em 5 rotas listadas em `NO_CONTROL` com o
  motivo (PDF que vem do banco, certidão emitida, arquivo no disco, assembleia convocada, bloqueio só pelo proprietário com
  vínculo ativo); nelas o filtro foi conferido no código.

Para isso, o preenchimento de linhas e o `php://input` do teste saíram do ADR 0340/0341 para `ScopeFixtures` e
`MovesOSTests\Fakes\ScopeTestInput`, usados pelos dois testes.

## Resultado

Nenhuma rota entregou ou alterou registro de outra unidade ou condomínio. Para conferir que o teste pega a falha, o filtro de
unidade de `ResidentPortal::cancelVisit` foi retirado por um instante: o teste apontou
`POST /resident/visits/{id}/cancel (vizinho) => 200`. O filtro foi devolvido.

## Observação

A migração antiga do portal reaplica o enum de funções sem `household` a cada execução da suíte de integração; o teste apaga
essa linha no `tearDown` para não quebrar a execução seguinte.

## Fora desta fatia

Acesso das imobiliárias parceiras (`/partner-*`, ADR do acesso só leitura) e XSS nas telas.
