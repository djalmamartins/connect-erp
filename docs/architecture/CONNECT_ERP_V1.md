# Connect ERP v1 — Arquitetura validada (Primeira Execução)

Data da auditoria: 2026-11-07. Base auditada: branch `claude-dev` em `e007c18`
(worktree `worktrees/connect-claude-dev`), comparada com `claude-layout` (`03b9510`
+ alterações locais de CSS do Studio) e com a `main` servida em `https://www.connect.lab`.

Este documento cumpre a seção 13 do contrato oficial de arquitetura. Ele registra o
que existe de fato, compara com a estrutura-alvo v1 e lista apenas as adaptações
necessárias. Nada foi movido, substituído ou migrado nesta execução.

Decisões já vigentes que este documento respeita:

- ADR-001 (`docs/decisions/ADR-001-EXISTING-CORE.md`): reutilizar o núcleo existente.
- ADR-002 (`docs/decisions/ADR-002-ADMINISTRADORA-UNICA.md`): administradora única
  (Connect), vários condomínios, segregação por `condominium_id`; não é SaaS.
- Decisão 0002 (`docs/Organic Code/decisions/`): Help Desk e Suporte como temas próprios.

## 1. Como a aplicação realmente funciona

| Item | Implementação real |
|---|---|
| Entrada HTTP | `public/index.php` apenas inclui `index.php` da raiz. O vhost do container aponta para a raiz; o `.htaccess` da raiz bloqueia `source/`, `service/`, `tests/`, `vendor/` e áreas sensíveis de `storage/`. |
| Bootstrap | `index.php` da raiz: autoload Composer, `AppLogger::bootstrap()`, `Session`, `Router`, registro de rotas, `dispatch()` e redirecionamento de erro. |
| Autoload | PSR-4 `Source\` → `source/` e `Connect\` → `source/Connect/`. Arquivos carregados sempre: `source/Boot/{Config,Helpers,Connection,Settings}.php` e `source/Services/Minify/*.php`. |
| Configuração | `source/Boot/Config.php` lê variáveis de ambiente (`MOVESOS_DB_*`, `APP_DEBUG`, `APP_URL`, `MOVESOS_ENV`). `source/Boot/Settings.php` lê a tabela de configurações do banco e define `CONF_VIEW_*`, `CONF_ACCESS_*`, `CONF_MODE`. |
| Rotas | `Source\Core\Router` é um wrapper sobre `movescode/router` (`^1.1`) com middleware por rota. Cada tema registra rotas em seu `routes.php`, incluído pelo `index.php`. |
| Views | `Source\Core\View` sobre `movescode/view` (`Engine`). Controllers recebem a pasta do tema via `moves_container_path()`. |
| Controllers | `source/Controllers/{Web,Studio,Erp,App,Api,Pay}/...` (ver conflito C1). |
| Models | `source/Models/` organizado por domínio (`Corporation/`, `Erp/`, `Banking/`, `Post/`, `Support/`, `Notification/`, `Session/` ...), base `Source\Core\Model` (PDO). |
| Serviços | `source/Services/` (`TwoFactorService`, `CondominiumScope`, `Minify/*`) e utilitários em `source/Support/` (`Access`, `Audit`, `AppLogger`, `Totp`, `SecretBox`, `Upload`, `Middleware/ApiAuthenticated` ...). |
| Autenticação | `Source\Models\Auth` (sessão PHP). 2FA TOTP implementado em `e007c18` (`Support/Totp`, `Services/TwoFactorService`, `Controllers/Studio/TwoFactor`, `Controllers/Api/V1/TwoFactor`). |
| Permissões | `Source\Support\Access` sobre `access_permissions`, `access_roles`, `access_role_permissions`, `access_user_roles`, `access_user_overrides`, com fallback `legacy()` por nível. |
| Logs/auditoria | `Support/AppLogger` (Monolog 3), `Support/Audit`, `Models/Notification/AuditLog`, `Models/Session/AppLog`. |
| Banco | MariaDB 11.8. `Source\Core\Connect` (PDO singleton). Migrations do ERP em `database/migrations/` via `service/commands/database-migrate.php` (tabela `movesos_schema_migrations`, checksum, `.down.sql`). Histórico legado em `database/legacy-migrations/`. |
| API REST | Grupo `/api/v1` no `index.php`: `GET /health`, `POST /auth/2fa/verify`, `GET /me`, `GET /condominiums`, `GET /condominiums/{id}`. JSON por `Connect\Http\JsonResponse`. Contrato em `docs/api/openapi.yaml`. |
| Assets | Temas publicam `*.min.css/js` via `movescode/compress` (`source/Services/Minify/*`, `service/commands/build-studio-assets.php`). Bibliotecas compartilhadas em `container/shared/assets/vendor/`. |
| Docker | `compose.yaml` (projeto `connect-erp`): `app` (php:8.2-apache, `127.0.0.1:8088`, monta a pasta inteira), `legacy-db` (MariaDB 11.8, `connect_legacy`, usado pela aplicação) e `db` (MariaDB 11.8, `connect_erp`, porta 3308, sem uso). |
| Testes | PHPUnit 11.5 (`phpunit.xml`, suítes `unit` e `integration` em `tests/legacy/`), `tests/smoke.php`, CI em `.github/workflows/quality.yml`. |

## 2. Árvore real (claude-dev, sem `vendor/`)

```
connect/
├── .claude/                agents/ (12), skills/ (19), rules/, hooks/php-lint.sh, settings.json
├── .github/workflows/quality.yml
├── container/
│   ├── web/
│   │   ├── default/v1/     site público Connect (tema WEB real = "default")
│   │   │   ├── default.php, routes.php
│   │   │   ├── layouts/    theme.php, sales.php, error.php
│   │   │   ├── pages/      home, articles-*, legal, login/, header.php, footer.php, modals.php ...
│   │   │   └── assets/     css/, js/, fonts/, images/, svg/, site.min.css, site.min.js
│   │   └── support/v1/     suporte público (/suporte)
│   │       ├── default.php, routes.php, layouts/theme.php, pages/, assets/
│   ├── cms/
│   │   ├── studio/v1/      Studio migrado
│   │   │   ├── default.php, routes.php
│   │   │   ├── layouts/    studio.php, login.php, error.php
│   │   │   ├── components/ agenda, blog, dash, events, faqs, login, media, pages, proposals,
│   │   │   │               results, settings, slides, support, tickets, users, versions ...
│   │   │   └── assets/     css/, js/, fonts/, images/, audio/, vendor/, studio.min.css/js
│   │   └── helpdesk/v1/    default.php, routes.php (reusa layouts/components do Studio)
│   ├── erp/
│   │   └── connect/v1/     default.php e routes.php (placeholders vazios)
│   └── shared/assets/      js/, vendor/ (bibliotecas compartilhadas)
├── source/
│   ├── Boot/               Config, Connection, Helpers, Settings
│   ├── Core/               Connect, Controller, Model, Router, Session, View
│   ├── Controllers/        Web/{Connect,V1,Support}, Studio/{Studio,TwoFactor},
│   │                       Erp/{Connect,V1}, App/{Connect,V1}, Api/V1, Pay
│   ├── Models/             Auth, User, Address + pastas por domínio
│   ├── Services/           CondominiumScope, CondominiumAccessDenied, TwoFactorService, Minify/
│   ├── Support/            Access, Audit, AppLogger, Totp, SecretBox, Upload, Middleware/ ...
│   └── Connect/            Http/{HealthController,JsonResponse}, Infrastructure/DatabaseConnection,
│                           Support/bootstrap.php   (esqueleto anterior, ver C6)
├── database/
│   ├── migrations/         20261107_0001..0003 (+ .down.sql), README.md
│   └── legacy-migrations/  core/, migrations/, imports/
├── service/                commands/ (migrate, build, deploy-check ...), jobs/, workers/
├── docs/                   architecture/, api/, decisions/, migration/, specs/, process/,
│                           quality/, security/, banking/, context/, reference/, Organic Code/decisions/
├── tests/                  legacy/{Unit,Integration,bootstrap.php,run.php}, smoke.php
├── tools/                  (setup.py não encontrado)
├── docker/app/             Dockerfile, vhost.conf
├── organic/                biblioteca de UI Organic (CSS/JS/editor/fontes) — em remoção por outra frente
├── public/                 index.php, .htaccess
├── storage/                (ignorado pelo Git)
├── index.php, .htaccess, compose.yaml, composer.json, composer.lock, phpunit.xml
└── .env.example, .gitignore, CLAUDE.md, README.md
```

`src/` existe apenas na `main` como pasta vazia e não está versionada.

## 3. Comparação com a estrutura-alvo v1

| Alvo v1 | Situação real | Avaliação |
|---|---|---|
| `.claude/agents`, `.claude/skills` | Existem | OK |
| `container/web/{tema-web}/v1` | `container/web/default/v1` (+ `support/v1`) | OK. Nome do tema WEB confirmado: **`default`** (alias legado `connect_by_moves`). |
| WEB `components/{header,footer,navbar,sections}` | Header/footer/modais em `pages/` como parciais; `layouts/` | Divergência de organização (C3). Não mover agora. |
| `container/cms/studio/v1` | Existe, com `components/` rico e `layouts/` | OK; `layouts/` é o padrão real (C3). |
| `container/erp/connect/v1` | Só placeholders; o código não aponta para cá | **Conflito C2**. |
| ERP `components/`, `pages/` | Inexistentes | Criar conforme os módulos forem implementados, não antes. |
| `container/app` | Inexistente; o código procura `container/apps/residents/{tema}` | Conflito C2. |
| `container/send` | Inexistente; e-mails procuram `container/mail/{tema}` | Conflito C4. |
| `source/App/` | Controllers estão em `source/Controllers/` | **Conflito C1**. |
| `source/Models`, `source/Services` | Existem | OK |
| `public/index.php` | Existe, delega à raiz | OK (C7 para o docroot). |
| `database/migrations`, `seeders` | `migrations/` ativo; sem `seeders/` | OK. `seeders/` só quando houver seed real. |
| `docs/architecture`, `api`, `migration` | Existem | OK |
| `docs/design-system`, `modules`, `tests` | Inexistentes; há `docs/architecture/DESIGN_SYSTEM.md` e `docs/quality/TEST_STRATEGY.md` | Criar `docs/modules/` com o primeiro módulo; não duplicar os demais. |
| `tests/` | `tests/legacy/` + `smoke.php` | OK |
| `tools/` | Existe; `setup.py` ausente | OK |
| `docker/` | `docker/app/` | OK |
| MariaDB | 11.8 nos dois serviços | OK (C5 sobre qual banco). |

## 4. Conflitos encontrados

**C1. `source/App/` vs `source/Controllers/`.** O contrato e o `CLAUDE.md` do kit citam
`source/App/`, mas todos os controllers e namespaces reais estão em
`Source\Controllers\...`. Mover quebraria todas as rotas.
Decisão: `source/Controllers/` é a localização oficial; o contrato passa a ler
"controllers em `source/Controllers/`". Novos controllers do ERP:
`source/Controllers/Erp/Connect/` (web) e `source/Controllers/Api/V1/` (API).

**C2. Resolução de caminho do ERP e do APP não segue `container/{ambiente}/{tema}/v1`.**
`moves_container_path('erp', ...)` resolve para `container/apps/erp/{tema}` (sem `v1`,
alias `connect` → `default`), e `index.php` inclui `default.php` do ERP como arquivo de
rotas. O mesmo vale para `residents` (`container/apps/residents/...`, usado também em
`Studio.php`). Resultado: `container/erp/connect/v1` nunca é carregado, nenhuma rota
`/erp` existe (confirmado: `https://www.connect.lab/erp` responde 404) e os controllers
`Erp/Connect/*` estão órfãos. Adaptação: ver A1.

**C3. Pasta `layouts/` dentro dos temas.** O contrato proíbe "layouts paralelos" e pede
`components/`. Os temas reais (WEB, Support, Studio) usam `layouts/*.php` como moldura
HTML, e `default.php` hoje é só um comentário. Não é um layout paralelo, é a moldura
única de cada tema. Decisão: aceitar `layouts/` nos temas migrados até a migração dos
temas para `default.php` como moldura; no tema ERP novo, a moldura fica em `default.php`
e não se cria `layouts/`.

**C4. Templates de e-mail sem diretório.** `Auth` e `Users` renderizam e-mails a partir de
`moves_container_path('mail', CONF_VIEW_MAIL)` → `container/mail/default`, que não existe.
Recuperação de senha e convites podem falhar. O ambiente `send` do contrato também não
existe. Adaptação: A4.

**C5. Dois bancos MariaDB.** A aplicação usa `legacy-db` (`connect_legacy`); o serviço `db`
(`connect_erp`) está ligado mas sem uso. As migrations do ERP (`database/migrations`)
estendem as tabelas existentes (`users`, `access_*`), então pertencem ao mesmo banco da
aplicação. Decisão: um único banco (`legacy-db`) para o núcleo e o ERP; o serviço `db`
fica sem uso até uma decisão explícita de removê-lo (não removido agora).

**C6. Esqueleto `source/Connect/` paralelo ao núcleo.** `Connect\Infrastructure\DatabaseConnection`
e `Connect\Http\HealthController` duplicam `Source\Core\Connect` e o `/health` inline do
`index.php`; só `tests/smoke.php` os usa. `Connect\Http\JsonResponse` é usado pela API.
Adaptação: A5.

**C7. Docroot é a raiz do projeto.** O vhost publica a raiz e protege as pastas por
`.htaccess`; `public/` é só um repasse. Funciona, mas depende do `.htaccess` para não
expor código. Não mudar agora; registrar como risco e revisar no hardening.

**C8. Testes dependem do banco já no autoload.** `composer.json` carrega
`source/Boot/Settings.php` em todo autoload e ele consulta o banco. Rodando a suíte
`unit` fora do container, o boot falha antes do primeiro teste
(`getaddrinfo for db failed` seguido de `Undefined constant "CONF_URL_SSL"` em
`Helpers.php:297`, porque `Connect::getInstance()` tenta redirecionar antes de as
constantes de URL existirem). Ou seja, até testes unitários exigem MariaDB, e a falha de
conexão vira erro fatal em vez de mensagem clara.

**C9. Autorização com fallback permissivo.** `Access::can()` usa `legacy()` (libera por
nível, inclusive para nível ≥ 5) quando a permissão não está cadastrada **e** quando a
consulta falha por exceção. Para módulos financeiros isso é inaceitável. Adaptação: A3.

**C10. Pastas `organic/` e `src/`.** Outra frente está eliminando `organic/` e `src/` e
migrando para a base. Os layouts WEB (`layouts/theme.php`, `sales.php`, `error.php`) e
`Services/Minify/{App,Erp}.php` ainda referenciam `/organic/*.css|js`. Esta execução não
toca nesses arquivos para não conflitar; a remoção precisa atualizar essas referências.

**C11. Duplicidade de controllers `V1` e `Connect`.** `Erp/V1` e `Erp/Connect`, `App/V1` e
`App/Connect`, `Web/V1` e `Web/Connect` são versões legadas lado a lado. Não renomear;
o ERP v1 usa apenas `Erp/Connect` e as `V1` ficam congeladas até serem descartadas por ADR.

## 5. Adaptações propostas (somente as necessárias)

Nenhuma delas foi aplicada nesta execução. Ordem sugerida:

- **A1. Carregar o tema ERP no padrão do contrato.** Em `moves_container_path()` e
  `moves_container_url()`, resolver `erp` para `container/erp/{tema}/v1` com alias
  `default`/`connect` → `connect`; no `index.php`, incluir `routes.php` do ERP (não
  `default.php`). Atualizar a configuração `view_erp` por migration reversível (o valor
  atual vem de `20260829_canonical_container_paths.sql`). Mesma correção para `residents`
  → `container/app/{tema}/v1` quando o APP for retomado. Teste: unidade para
  `moves_container_path('erp','connect')` e smoke de `GET /erp` respondendo login/302.
- **A2. Tema ERP mínimo.** `container/erp/connect/v1/default.php` como moldura única
  (sidebar, navbar, conteúdo via `$this->section('content')` do MovesCode View),
  `routes.php` com grupo `/erp` e namespace `Source\Controllers\Erp\Connect`, e só os
  `components/` e `pages/` do primeiro módulo (Condomínios). Responsabilidade do
  `claude-layout` para a interface e do `claude-dev` para rotas/controllers.
- **A3. Endurecer `Access::can()`.** Permissões com prefixo `erp.` e `api.` negam por
  padrão quando não cadastradas, e qualquer exceção na checagem nega acesso em vez de
  cair no nível legado. Cadastrar as permissões do ERP na migration `0003` já existente.
- **A4. Templates de e-mail.** Confirmar onde os templates de e-mail do legado estão e
  restaurá-los em `container/send/default/v1` (ambiente `send`), com alias `mail` →
  `send` em `moves_container_path()`. Até lá, registrar a falha como pendência.
- **A5. Absorver `source/Connect/`.** Manter `JsonResponse` (mover para
  `Source\Support\Http\JsonResponse` só por ADR), remover `HealthController`,
  `DatabaseConnection` e `bootstrap.php` depois que `tests/smoke.php` usar o bootstrap
  real. Coordenar com a frente de remoção de `organic/`/`src/`.
- **A6. Boot testável.** Fazer `Connect::getInstance()` lançar exceção quando chamado
  antes das constantes de URL (ou em `MOVESOS_ENV=testing`) em vez de redirecionar, para
  que a suíte `unit` rode sem banco e a `integration` rode no container.
- **A7. `docs/modules/`.** Criar com a especificação do primeiro módulo (Condomínios),
  sem duplicar `docs/specs/`.

## 6. Árvore definitiva validada (v1)

Diferenças em relação ao alvo do contrato estão marcadas com `*`.

```
connect/
├── .claude/{agents,skills,rules,hooks}/
├── container/
│   ├── web/default/v1/            * tema WEB "default"; layouts/ aceito (C3)
│   ├── web/support/v1/            * suporte público
│   ├── cms/studio/v1/             Studio; layouts/ aceito (C3)
│   ├── cms/helpdesk/v1/           * Help Desk, só rotas (decisão 0002)
│   ├── erp/connect/v1/
│   │   ├── default.php            moldura única do ERP
│   │   ├── routes.php             grupo /erp, MovesCode Router
│   │   ├── assets/{css,js,images,fonts,icons}/
│   │   ├── components/            criados sob demanda (layout, sidebar, navbar, tables, forms ...)
│   │   └── pages/{modulo}/        kebab-case, criados por módulo
│   ├── app/{tema}/v1/             * hoje em container/apps/residents (A1, quando retomado)
│   ├── send/{tema}/v1/            * templates de e-mail (A4)
│   └── shared/assets/             * bibliotecas compartilhadas entre temas
├── source/
│   ├── Boot/  Core/               núcleo existente
│   ├── Controllers/               * oficial (C1): Erp/Connect, Api/V1, Studio, Web
│   ├── Models/                    por domínio
│   ├── Services/                  regras de negócio (única implementação)
│   └── Support/                   utilitários, Access, Middleware
├── database/migrations/           AAAAMMDD_NNNN_*.sql + .down.sql
├── database/legacy-migrations/    * histórico, somente leitura
├── service/                       * comandos CLI, jobs, workers
├── docs/{architecture,api,decisions,migration,modules,specs,process,quality,security}/
├── tests/legacy/                  * Unit/ e Integration/ (PHPUnit 11.5)
├── tools/  docker/app/
├── public/index.php               repasse para index.php (C7)
└── index.php, compose.yaml, composer.json, phpunit.xml, CLAUDE.md, .env.example
```

## 7. Responsabilidades

| Diretório | Responsabilidade | Não pode conter |
|---|---|---|
| `container/web` | Site público e suporte público | Telas internas, ERP, Studio |
| `container/cms` | Studio e Help Desk (conteúdo, mídia, usuários do site, chamados) | Regras financeiras |
| `container/erp` | Interface da gestão condominial | Regra financeira, SQL, cálculo de saldo |
| `container/app` | Aplicativo do condômino | Código do ERP |
| `container/send` | Templates e recursos de comunicação | Lógica de envio (fica em `service/` e `Support/Email`) |
| `source/Controllers` | Receber requisição, validar entrada, chamar serviço, responder | Regra de negócio |
| `source/Services` | Regras de domínio, transações, idempotência, escopo de condomínio | HTML |
| `source/Models` | Acesso a dados com SQL parametrizado | Regra de apresentação |
| `database/migrations` | Esquema versionado e reversível | Dados de produção |
| `tests/` | Testes unitários e de integração | Credenciais reais |

Fluxo obrigatório: Interface → Controller (rota MovesCode) → Serviço → Model → MariaDB.
A API `/api/v1` usa os mesmos serviços que as páginas.

## 8. Convenções

- Classes PascalCase; métodos e variáveis em camelCase, como no núcleo.
- Rotas e diretórios de páginas em kebab-case (`/erp/contas-receber`); recursos da API em
  inglês e plural, como os já publicados (`/api/v1/condominiums`).
- Tabelas e colunas em inglês snake_case, seguindo `users`, `access_*`, `condominiums`.
- Todo registro de condomínio tem `condominium_id`, e toda consulta passa por
  `Source\Services\CondominiumScope`.
- Dinheiro em `DECIMAL`, operações financeiras idempotentes, trilha via `Support/Audit`.
- Respostas JSON: `{"data": ...}` em sucesso, `{"error": {"code","message"}}` em erro,
  paginação por `page`/`per_page`, documentadas em `docs/api/openapi.yaml`.
- Sem tabelas de planos, assinaturas ou administradoras clientes (ADR-002).
- Branches: `claude-dev` (backend), `claude-layout` (interface); merge na `main` só com
  aprovação do Djalma.

## 9. Verificações executadas nesta auditoria

| Verificação | Comando ou método | Resultado |
|---|---|---|
| Sintaxe PHP | `php -l` em todos os 242 arquivos PHP versionados do `claude-dev` (PHP 8.5.9 local) | 0 erros |
| PHPUnit `unit` | `php vendor/bin/phpunit --testsuite unit` fora do container | Não executou: boot exige banco (C8) |
| Health | `https://www.connect.lab/api/v1/health` | `status: ready`, `database: ready` |
| WEB | `https://www.connect.lab/` | Página "Connect Condomínios" carregada |
| Studio | `https://www.connect.lab/studio/login` | Tela de login carregada |
| Suporte, ERP, `/api/v1/me` | navegador | 404, porque o lab serve a `main`, que ainda não tem esses commits; `/erp` também daria 404 no `claude-dev` (C2) |
| Docker e MariaDB | `compose.yaml` lido | Não acessível pelo sandbox (sem socket Docker) |

Comandos para rodar no Mac, na pasta do worktree ou da `main`:

```bash
docker compose -p connect-erp ps
```

```bash
docker compose -p connect-erp exec app composer db:status
```

```bash
docker compose -p connect-erp exec app vendor/bin/phpunit --testsuite unit
```

```bash
docker compose -p connect-erp exec legacy-db mariadb -u root -p -e "SELECT view_theme, view_erp, view_app, view_mail FROM connect_legacy.settings LIMIT 1"
```

A tabela `settings` vem de `Source\Models\Settings\Settings`. Como o container monta `www/connect` (`main`), os
testes do `claude-dev` só rodam no container depois de levar os commits para lá ou
subir o compose a partir do worktree.

## 10. Próximos passos

1. Djalma roda os comandos da seção 9 e decide sobre C5 (banco único).
2. `claude-dev`: A3 (Access) e A6 (boot testável), depois rodar PHPUnit no container e
   fechar a Fase 1 (`e007c18`).
3. `claude-dev` + `claude-layout`: A1 e A2, abrindo o primeiro módulo (Condomínios).
4. A4 e A5 coordenados com a frente que remove `organic/` e `src/`.
