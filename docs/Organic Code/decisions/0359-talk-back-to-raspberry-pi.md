# ADR 0359 — Connect Talk volta ao projeto separado no Raspberry Pi

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0308 (API do Talk), ADR 0324 (Talk dentro do ERP), ADR 0349 (WhatsApp no Pi e worker pelo cron),
  ADR 0353 (Talk no relatório e na anonimização do titular), ADR 0357 (.env de produção), ADR 0358 (cron da hospedagem)
- Substitui, na parte da hospedagem: ADR 0349 (worker pelo cron) e a linha do worker do ADR 0358

## Contexto

Em 16/11 o Djalma pediu o Talk dentro do ERP (ADR 0324 e 0349). Em 17/11 ele mudou: o Talk volta a ser o projeto
anterior (`djalmamartins/talk`), inteiro, instalado num Raspberry Pi no escritório, com um nobreak só para o Pi e para a
internet. Ele aceita que o Talk caia junto se faltar luz ou internet no escritório.

## Decisão

- O Talk dentro do ERP fica **pausado**. A hospedagem (Cloud Startup) não roda o Talk: sai do HOSPEDAGEM.md §4 o cron do
  `tools/talk-worker.php`, e o `.env.production.example` deixa as chaves `TALK_*` e `EVOLUTION_*` comentadas.
- O Talk do Pi fala com o ERP pelas rotas `/talk/...` da API, com o token `ctk_` (ADR 0308), que continuam ativas.
- O código e as tabelas do Talk **ficam no ERP**: o relatório e a anonimização do titular (ADR 0353) leem e limpam
  `talk_contacts`, `talk_messages` e afins, e o código pode voltar a ser usado se a decisão mudar. Nenhuma migration.
- O host `talk.<domínio>` do ERP só responde se tiver DNS apontando para a hospedagem; o DNS do Talk aponta para o Pi.

## Testes

- `HostingCronEnvTest::testHostingGuideHasNoTalkWorkerCron`: o roteiro não tem linha de cron com `talk-worker.php`.
- `EnvironmentKeysTest`: o modelo de produção não tem nenhuma chave `TALK_`/`EVOLUTION_` ativa.

## Riscos

- Os dados do Talk do Pi ficam no Pi: o relatório do titular do ERP não os enxerga. Pedido do titular precisa ser
  atendido também no Talk do Pi (anotado no registro LGPD como pendente do projeto separado).
