# Permissões do Claude Code no Mac mini

Objetivo: o Claude Code trabalhar nas pastas do Connect ERP sem pedir aprovação a
cada comando, mantendo bloqueado o que já causou incidente ou não pode ser desfeito.

## Instalar

```bash
cd "/Volumes/MAC HD/Docker/xampp/www/connect"
bash tools/claude/liberar-permissoes.sh
```

Sem argumentos, aplica em `xampp/www/connect` e em cada worktree de `xampp/worktrees/`.
Com argumentos, aplica só nos caminhos informados. Depois, reabra as sessões do Claude Code.

## O que muda

- `.claude/settings.local.json` (local, fora do Git) passa a permitir Bash, Edit, Write,
  Read, Glob, Grep, WebFetch e WebSearch, com `defaultMode: acceptEdits`.
- O `settings.json` versionado não é tocado; as regras dele continuam valendo.
- `~/.claude/hooks/connect-erp-guard.sh` roda antes de todo comando Bash e bloqueia:
  - `phpunit` direto (incidente de 07/11/2026; use `composer test:unit` / `composer test:integration`);
  - `DROP DATABASE/TABLE/SCHEMA` e `TRUNCATE` fora de banco `*_test`;
  - `git push` com `--force`, `-f`, `--force-with-lease`, `--mirror`, `--delete` ou refspec `+`;
  - `git push` para a `main`, inclusive `git push` sem argumentos quando a branch atual é a `main`;
  - `docker compose down -v`, `docker volume rm/prune`, `docker system prune`;
  - `sudo` e `rm -rf` em `/`, `~`, `/Volumes`, `/Users`, `.git`, `.` ou `*`.
- Pedem confirmação: `ssh`, `scp`, `rsync`, `composer db:rollback`, `composer db:baseline`,
  `gh pr merge`.
- A leitura de `.env`, `.local/**` e `*-key.pem` continua negada.

## Desfazer

```bash
bash tools/claude/liberar-permissoes.sh --desfazer
```

Restaura o `settings.local.json` anterior a partir de `.claude/backups/` (ou o remove,
se não existia). A guarda em `~/.claude/hooks/` fica inofensiva sem o settings.

## Testar a guarda

```bash
bash tools/claude/test-guard.sh
```

Executa só a guarda com comandos simulados; nenhum comando é rodado de verdade.
Precisa de `python3` para montar o JSON de teste.

## Limites conhecidos

- A guarda trabalha por padrões de texto. Um comando montado de forma indireta
  (variáveis, `eval`, script novo que chama `phpunit`) pode passar. A trava do próprio
  suite de testes, que aborta fora de banco `*_test`, continua sendo a proteção principal.
- O modo `bypassPermissions` não é usado de propósito.
