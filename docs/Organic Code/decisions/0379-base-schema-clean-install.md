# ADR 0379 — Estrutura base para a instalação limpa da produção

- Status: Aceito (passo 1); passos 2 e 3 PENDENTES
- Data: 17/11/2026
- Relacionados: HOSPEDAGEM.md §3 (primeira implantação), ADR 0369 (integração no CI)

## Contexto (CONFIRMADO em 17/11/2026)

O roteiro da primeira implantação manda rodar `composer db:migrate` num banco recém-criado no hPanel. Num banco vazio isso
**falha** na primeira migration que aponta para `users`:

```
Falha na migration: ... Can't create table `user_two_factor` (errno: 150 "Foreign key constraint is incorrectly formed")
```

As tabelas base (`users`, `access_*`, `settings`, `mail_queue`, `movesos_versions`, `studio_support_*`...) nunca vieram de
migration: o laboratório as tem desde o banco antigo do Organic Code, e os testes as criam à mão em
`tests/legacy/bootstrap.php`. `database/legacy-migrations/` não cria `users`. Conferido também que a estrutura completa
(exportada do banco de teste) seguida de `composer db:migrate` funciona: as migrations do ERP são idempotentes.

## Decisão

1. **(feito)** `bash tools/lab-exportar-esquema.sh` exporta só a estrutura do banco do laboratório (`--no-data`, sem
   `AUTO_INCREMENT=N` nem `DEFINER`; para se o dump trouxer `INSERT`) para `database/base/schema.sql`, e a lista de
   tabelas com quantidade de linhas para `database/base/tables.txt`. Nenhum dado pessoal sai; os arquivos podem ir para o
   Git.
2. **(PENDENTE, depende do passo 1 rodar no Mac)** Com a estrutura real: migration base `database/base/schema.sql`
   aplicada antes das migrations, dados mínimos de catálogo (papéis e permissões, módulos) e um comando para criar o
   primeiro administrador com 2FA. A lista `tables.txt` separa catálogo de dado de condomínio.
3. **(PENDENTE)** Job no CI de instalação limpa: banco vazio → estrutura base → `db:migrate` → `deploy-check`.

## Testes

`LabSchemaExportTest`: grava a estrutura sem contadores nem `DEFINER`, conta linhas, recusa dump com dados sem gravar
nada, e o comando padrão usa `--no-data`.
