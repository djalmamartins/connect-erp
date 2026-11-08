# 0012 — Banco único (C5)

Data: 2026-11-08 · Status: aceito (aprovado pelo Djalma em 2026-11-08)

## Contexto

O `compose.yaml` tinha dois MariaDB: `legacy-db` (`connect_legacy`), usado pela aplicação,
e `db` (`connect_erp`, porta 3308), ligado mas sem uso (C5 em
`docs/architecture/CONNECT_ERP_V1.md`). As migrations do ERP estendem `users`, `access_*` e
`settings`, que estão em `connect_legacy`. Para uma administradora só, um segundo banco
exigiria joins entre bases e backups duplicados, sem ganho nenhum. Além disso,
`tools/backup-database.sh` fazia backup do `db`, ou seja, não salvava os dados reais.

## Decisão

- O serviço `db` foi removido do `compose.yaml`. App e ERP usam somente `legacy-db`.
- O volume `connect_db_data` continua declarado e **não foi apagado**. Removê-lo exige
  backup e um ADR próprio.
- A suíte de testes usa um banco separado no mesmo servidor (`MOVESOS_TEST_DB`, padrão
  `movesos_test`, obrigatoriamente `*_test`), com a trava do commit `b56876f`. Só é
  executada por `composer test:*`, nunca por `vendor/bin/phpunit` direto.
- `tools/backup-database.sh` passa a fazer backup de `legacy-db`.
- `.env.example` não tem mais as variáveis `DB_*`/`CONNECT_DB_PORT` do serviço removido.

## Antes de recriar o ambiente

Confirmar que `connect_erp` está vazio (o sandbox desta sessão não acessa o Docker):

```bash
docker exec -it connect-erp-db-1 mariadb -u root -p -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='connect_erp'"
```

Com 0 tabelas, o container antigo pode ser removido com `docker compose up -d --remove-orphans`
(o volume permanece).
