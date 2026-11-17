# ADR 0369 — Integração no CI com MariaDB

- Status: Aceito
- Data: 17/11/2026
- Relacionados: incidente de 07/11 (`vendor/bin/phpunit` direto truncou o banco real), ADR 0368 (extensões no CI)

## Contexto

O CI (`quality.yml`) rodava só `composer test:unit:offline` (unidade sem banco) e os smokes de renderização. A suíte de
integração (687 testes: isolamento por condomínio, IDOR, XSS, financeiro, bancos em sandbox simulado) só rodava quando
alguém lembrava, aqui ou no Mac (`tools/testar-main.sh`). Um PR podia quebrar a integração e ser mesclado com o CI verde.

## Decisão

Novo job `php-integration` no `quality.yml`:

- serviço `mariadb:11.8` (a mesma versão do `compose.yaml` do laboratório), senha `root` só do contêiner do CI;
- `composer test:unit` (com banco) e `composer test:integration`, sempre pelos scripts do composer, que fixam
  `MOVESOS_ENV=testing` e o banco `movesos_test` (nunca `vendor/bin/phpunit` direto);
- mesmas extensões do job de unidade (ADR 0368).

O job leva ~9 minutos. Roda em paralelo com o job `php`.

## Testes

`CiIntegrationJobTest`: o workflow tem o job, a imagem do MariaDB, o host do banco de teste e o comando de integração, e
não chama `vendor/bin/phpunit`. A prova real é o próprio CI deste PR rodando a integração.
