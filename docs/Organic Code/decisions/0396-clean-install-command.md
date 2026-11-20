# 0396 — Instalação limpa do banco da produção (`composer db:install`)

- Status: Aceito
- Data: 18/11/2026
- Relacionados: ADR 0379 (passos 2 e 3), ADR 0380 (primeiro administrador), HOSPEDAGEM.md §3

## Contexto
O banco da produção começa vazio e `composer db:migrate` falhava nele, porque as tabelas base (`users`, `access_*`,
`settings`...) nunca vieram de migration (ADR 0379). Em 18/11/2026 o Djalma rodou `tools/lab-exportar-esquema.sh` no Mac e
mandou a estrutura do laboratório (PR #668): 191 tabelas, sem nenhum dado.

Ensaio num MariaDB vazio: só a estrutura + migrations passa, mas sobra 1 papel (de 7) e nenhuma linha em `settings`, sem a
qual o sistema não liga. Os papéis e boa parte das permissões vêm das migrations antigas
(`database/legacy-migrations/migrations`), que o `db:migrate` não roda.

## Decisão
`composer db:install` (`service/commands/database-install.php` + `Source\Support\CleanInstall`):
1. Recusa se o banco tiver qualquer tabela (para atualizar, `db:migrate`) e se `schema.sql` trouxer `INSERT`.
2. Carrega `database/base/schema.sql`.
3. Roda as migrations antigas comando por comando; as que não repetem em cima da estrutura nova (tabela que já existe,
   chave que não existe mais) são anotadas e puladas, como `mariadb --force`. Hoje são 3.
4. Cria `settings` id 1: nome Connect Condomínios, `site_domain_ssl` do `APP_URL`, temas que existem em `container/`,
   fuso de São Paulo e os cinco acessos ligados. O resto (e-mail, redes sociais) se ajusta em Studio > Configurações; o
   SMTP vem do `.env` (ADR 0060).
5. Roda `database-migrate.php apply` e lembra o `criar-admin` e o `deploy-check`.

O comando não carrega `vendor/autoload.php`: o autoload liga o sistema, que lê `settings`, e o banco ainda está vazio.

## Consequências
- Conferido em 18/11/2026 num MariaDB 10.11 vazio: 191 tabelas, 7 papéis, 140 permissões, configuração criada, 106
  migrations; `criar-admin` cria o administrador e o `deploy-check` passa no banco (os erros restantes eram de pastas e
  `.env` do contêiner de teste). Rodar de novo para com aviso.
- Nenhuma pessoa entra na instalação: `users` fica vazia até o `criar-admin`.
- `CleanInstallIntegrationTest` instala do zero num banco próprio (`movesos_clean_install_test`) no CI, roda as migrations
  e confere a recusa da segunda vez (passo 3 do ADR 0379). `CleanInstallStatementsTest` confere a separação dos comandos.
- Se o laboratório ganhar tabela fora de migration, é preciso exportar de novo a estrutura.
