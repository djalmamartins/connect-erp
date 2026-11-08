# 0006 — Autorização fail-closed e suíte unit sem MariaDB

Data: 2026-11-07 · Status: aceito (aguarda execução da suíte `integration` no container)

## Contexto

A auditoria `docs/architecture/CONNECT_ERP_V1.md` registrou dois bloqueios da Fase 1:

- **C9**: `Source\Support\Access::can()` caía em `legacy()` (libera por nível) quando a
  permissão não estava cadastrada, quando o usuário não tinha papel e quando a consulta
  lançava exceção. `erp.access` liberava a partir do nível 2.
- **C8**: o autoload do Composer carrega `source/Boot/Settings.php`, que consulta o banco.
  Sem MariaDB, `Connect::getInstance()` chamava `redirect()` antes de `CONF_URL_SSL` existir
  e o PHPUnit morria com `Undefined constant "CONF_URL_SSL"`, sem rodar nenhum teste.

## Decisão

**A3: autorização fail-closed.**
- `Access::STRICT_PREFIXES = ['erp.', 'api.', 'security.']`: sem registro de permissão ou
  sem papel para o usuário, essas permissões são negadas (`Access::fallback()`).
- As demais permissões (Studio, conteúdo) mantêm o legado `nível >= 5`, para não quebrar
  o Studio em uso.
- Qualquer exceção na checagem nega, para todas as permissões, e o resultado não entra
  no cache da requisição.
- O papel `developer` continua liberando toda permissão cadastrada. Permissões do ERP
  seguem cadastradas na migration `20261107_0003_erp_permissions.sql`.

**A6: boot testável.**
- `Connect::getInstance()` lança `RuntimeException` ("Banco de dados indisponível") em
  CLI, em `MOVESOS_ENV=testing` ou antes das constantes de URL, em vez de redirecionar.
  Na web, com as constantes definidas, continua redirecionando para `/ops/connect`.
- Modo opt-in `MOVESOS_TEST_DB_DISABLED=1`, aceito **somente** com `MOVESOS_ENV=testing`
  (`Connect::databaseDisabled()`): `Settings.php` usa os mesmos valores semeados em
  `tests/legacy/prepare-environment.php`, o bootstrap não prepara o banco e os testes
  `MovesOSTests\Integration\*` são pulados. Teste unitário que tocar no banco falha com
  exceção explícita.
- `composer test:unit:offline` e um passo no CI (`.github/workflows/quality.yml`) rodam a
  suíte `unit` nesse modo.

## Consequências

- Usuário sem papel em `access_user_roles` perde o atalho "ERP" no seletor de sistemas do
  Studio e qualquer acesso `erp.*`/`api.*`/`security.*`, mesmo com nível alto. É o
  comportamento esperado para o módulo financeiro; quem precisar de acesso recebe papel.
- Indisponibilidade do banco durante a checagem nega acesso também no Studio.
- A suíte `integration` ainda precisa rodar no container (seção 9 do documento de
  arquitetura); os novos casos estão em `tests/legacy/Integration/AccessControlTest.php`.
