# Rollback

Esta migração não sobrescreve `connect_erp`. Desde o ADR 0010 (C5) o serviço `db` não existe
mais no `compose.yaml`, mas o volume `connect-erp_connect_db_data` continua preservado. Para
parar o ambiente:

```bash
cd '/Volumes/MAC HD/Docker/xampp/worktrees/connect-claude-dev'
docker compose stop app legacy-db
```

Para voltar a executar a fundação da `main`, recrie o app a partir do checkout
principal. Não remova volumes durante o rollback normal.

O volume legado é `connect-erp_connect_legacy_db_data`. Sua exclusão é
destrutiva e exige aprovação explícita e backup validado. Para backup:

```bash
docker compose exec -T legacy-db sh -c 'mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' | gzip > .local/legacy-backup.sql.gz
```

Arquivos WEB/CMS estão apenas no branch `claude-dev`; `main`, `claude-layout` e
`container/erp/` não foram modificados. O rollback de código é trocar o checkout
usado pelo container, sem reset destrutivo ou merge reverso.
