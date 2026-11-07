# Setup local do Connect ERP

Última validação: 07/11/2026 (America/Sao_Paulo).

## Estado e caminhos

- Projeto principal (`main`): `/Volumes/MAC HD/Docker/xampp/www/connect`
- Claude Layout (`claude-layout`): `/Volumes/MAC HD/Docker/xampp/worktrees/connect-claude-layout`
- Claude Dev (`claude-dev`): `/Volumes/MAC HD/Docker/xampp/worktrees/connect-claude-dev`
- Base Organic Code preservada: `/Volumes/MAC HD/Docker/xampp/www/organiccode`
- Infraestrutura XAMPP compartilhada: `/Volumes/MAC HD/Docker/xampp`

O remoto Organic Code tinha em `main` somente o README inicial no commit
`f33e9dca8117f4af797110003aed24f22a52c58f`. A fundação local, sem Git, foi
copiada para este projeto sem `.env`, `.local`, certificados ou `.mcp.json`.
O projeto-base original não foi alterado. O contrato original está em
`docs/reference/ORGANIC_CODE_ENGINEERING_CONTRACT.md`.

O runtime MovesCode e os módulos existentes de autenticação, usuários,
permissões e logs não estavam presentes na base disponível. Eles não foram
recriados; a importação/compatibilidade permanece pendente antes dos módulos de
negócio. O bootstrap HTTP atual é uma fundação mínima e temporária.

## Pré-requisitos

- Docker Desktop com Compose v2
- Git
- Claude Code
- Opcional no host: PHP 8.2+ e Composer 2

Não é necessário instalar PHP no host para trabalhar: o container `app` inclui
PHP 8.2, Composer, PDO MySQL, Intl, Zip, Apache e mod_rewrite.

## Configuração inicial em outro computador

```bash
cd /caminho/do/connect
cp .env.example .env
openssl rand -hex 24
openssl rand -hex 24
```

Coloque valores diferentes gerados acima em `DB_PASSWORD` e
`DB_ROOT_PASSWORD`. O `.env` é ignorado pelo Git. Depois:

```bash
docker compose config --quiet
docker compose up -d --build
docker compose ps
curl -fsS http://127.0.0.1:8088/api/v1/health
```

Parar sem apagar dados:

```bash
docker compose stop
```

Retomar:

```bash
docker compose start
```

Remover containers e rede, preservando o volume do banco:

```bash
docker compose down
```

Nunca usar `docker compose down -v` sem uma decisão explícita de descarte do
banco local.

## Serviços, portas e endereços

| Serviço | Container | Endereço | Estado validado |
|---|---|---|---|
| Aplicação | `connect-erp-app-1` | `http://127.0.0.1:8088` | healthy |
| API REST | `connect-erp-app-1` | `http://127.0.0.1:8088/api/v1/health` | ready |
| MariaDB 11.8 | `connect-erp-db-1` | `127.0.0.1:3308` | healthy |
| HTTPS compartilhado | `dev-apache` | `https://www.connect.lab` | responde |

O VirtualHost e o certificado de `connect.lab` já existiam. O Apache
compartilhado monta a árvore `xampp/www` e serve o checkout `main`. Nenhum
arquivo global de hosts, certificado ou VirtualHost foi alterado nesta missão.

O banco usa volume `connect-erp_connect_db_data`, `utf8mb4`, collation
`utf8mb4_unicode_ci` e isolamento `READ-COMMITTED`. O usuário da aplicação só
enxerga `connect_erp` e `information_schema`.

## Desenvolvimento e testes

```bash
composer validate --strict
composer lint
composer test
docker compose exec app composer dump-autoload --optimize --strict-psr
docker compose exec app composer test
curl -fsS http://127.0.0.1:8088/api/v1/health
```

Contrato OpenAPI: `docs/api/openapi.yaml`. Migrations futuras ficam em
`database/migrations/` e não são executadas automaticamente. Backup manual:

```bash
tools/backup-database.sh
```

O backup comprimido é criado em `.local/backups/`, fora do Git. Um restore deve
ser ensaiado em banco descartável antes de ser considerado validado.

## Claude Code e divisão de trabalho

Cada processo deve permanecer no seu próprio worktree:

```bash
cd '/Volumes/MAC HD/Docker/xampp/worktrees/connect-claude-layout'
claude
```

```bash
cd '/Volumes/MAC HD/Docker/xampp/worktrees/connect-claude-dev'
claude
```

O Layout altera frontend, design system e temas. O Dev altera banco, backend,
REST, segurança e testes. Ambos leem `CLAUDE.md`, 172 specs de páginas, agentes,
skills e regras em `.claude/`. Não configurar `.mcp.json` automaticamente;
`.mcp.example.json` é apenas referência e cada servidor deve ser confirmado como
instalado antes de habilitar.

Fluxo de integração: revisar e testar a branch, fazer merge aprovado em `main`
e então atualizar `claude-layout` e `claude-dev` por fast-forward/merge revisado.
Não criar branches permanentes adicionais sem autorização.

## Validação executada

- Docker Compose validado e ambos os containers saudáveis.
- PHP 8.2.35 e Composer 2.11.1 confirmados dentro do container.
- `composer validate --strict`, lint de todos os PHP e smoke tests aprovados.
- Autoload PSR-4 otimizado e validado no container.
- MariaDB conectou pela credencial restrita; charset, collation e isolamento
  conferidos por consulta real.
- `/` e `/api/v1/health` responderam JSON; saúde confirmou MariaDB `ready`.
- Apache compartilhado passou em `configtest`; `https://www.connect.lab`
  respondeu pelo certificado/VHost já existentes.
- Serviços compartilhados em 8080, 8081, 8090, 8091 e 8025 continuaram
  respondendo após a instalação.
- Claude Code 2.1.316 respondeu a `--version` e `--help`; `CLAUDE.md`, agentes,
  skills e regras estão presentes nos dois worktrees. Uma sessão interativa não
  foi aberta nesta preparação.

Login não foi testado: o runtime e as credenciais de teste do Organic Code não
estão presentes. Integrações bancárias reais, migrations de negócio e dados do
APControle não foram executados, conforme o escopo de preparação.
