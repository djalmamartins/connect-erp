# 0651 — Teste de restauração com o usuário próprio do banco de teste

- Status: Aceito
- Data: 26/11/2026
- Contexto: implantação na hospedagem com o Djalma, 26/11/2026. Relacionadas: ADR 0325 (backup), 0334 (teste de
  restauração), 0357 (`.env` da hospedagem).

## Contexto

O `restore-check.sh` (ADR 0334) restaurava o backup num banco de teste usando o usuário do banco principal e só aceitava
nomes com "restore". No hPanel da Hostinger cada banco nasce com um usuário próprio, e o nome foi cortado:
`connect_restore` virou `u807812373_connect_restor`, com o usuário `u807812373_cnnctrestor`. Com isso o teste nunca
rodaria na hospedagem.

## Decisão

- Novas chaves opcionais no `.env`: `BACKUP_RESTORE_USER` e `BACKUP_RESTORE_PASS`. Quando existem, tudo o que toca o
  banco de teste (esvaziar, importar, contar) usa esse usuário; o banco real continua sendo lido só com o usuário do
  sistema, e só para as contagens. Sem as chaves, o comportamento é o de antes (mesmo usuário).
- `db-client-config.php restore` gera a seção `[client]` do banco de teste (arquivo 0600, senha fora da lista de
  processos, como no backup).
- A trava do nome passa a aceitar "restor" (o corte do hPanel). As outras travas continuam: diferente do banco do
  `.env` e só letras, números e `_`.

## Consequências

- O usuário do banco de teste não precisa (nem deve) ter acesso ao banco real.
- Testes: `RestoreCheckGuardTest` (nome cortado aceito pela trava) e `DbClientConfigRestoreTest`.
