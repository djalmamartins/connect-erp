# 0603 — Telas e worker do Connect Talk removidos do ERP

- **Status:** aceito
- **Data:** 2026-11-20
- **Relacionados:** 0308 (API do Talk), 0324 e 0349 (Talk dentro do ERP, agora removido), 0353 (Talk no
  relatório e na anonimização do titular), 0359 (Talk de volta ao projeto separado no Raspberry Pi),
  0386 (2ª via do boleto para o Talk), revisão das ADRs em `docs/decisions/REVISAO_V1_ADRS.md`.

## Contexto

Desde 17/11 (ADR 0359) o Talk em uso é o projeto separado no Raspberry Pi do escritório. O Talk que vivia dentro
do ERP (host `talk.<domínio>`, `container/talk`, worker pelo cron) ficou pausado. Na revisão das ADRs para a v1.0
foi proposto removê-lo, e o Djalma aprovou em 20/11/2026 ("sim pode remover").

## Decisão

Sai do ERP:
- `container/talk/` (telas: caixa de entrada, fila, contatos, campanhas, agenda, relatórios, equipe, conexões);
- o desvio do `index.php` para o host `talk.` e o ramo `talk` de `moves_container_path()`;
- `source/Services/Talk/` inteiro, menos o que a API usa (abaixo): gateways do WhatsApp, Jack, mídia, tempo real,
  serviços de conversa e `Support/Db` e `Support/Crypto`;
- `tools/talk-worker.php` e os testes dessas classes.

Fica no ERP:
- a API `/api/v1/talk/...` com o token `ctk_` (`Controllers/Api/V1/Talk.php`, `TalkApiAuthenticated`,
  `TalkTokens`, `TalkHelpdesk`, `TalkCharges`) e o `tools/talk-token.php`, que o Talk do Pi usa;
- `Talk/Support/Phone`, usada no relatório do titular;
- as tabelas `talk_*` e a migration 20261116_1300: nenhuma migration nova. O relatório e a anonimização do
  titular (ADR 0353) continuam lendo e limpando essas tabelas, que ficam vazias na produção;
- o item **Talk** do alternador de ambientes, que abre `talk.<domínio>`, cujo DNS aponta para o Pi.

## Consequências

- Se um dia o Talk voltar para dentro do ERP, o código está no histórico do Git (antes do merge desta ADR).
- Menos código para revisar na v1.0 (cerca de 70 arquivos a menos).
- Testes: unit e integração completos; os testes da API do Talk (`TalkIntegrationTest`) e do titular continuam.
