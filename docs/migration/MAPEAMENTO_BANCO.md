# Mapeamento do banco

O dump MariaDB 11.8 contém 53 tabelas, 69 blocos `INSERT`, 154 índices e 16
FKs declaradas. Inclui conteúdo/CMS, usuários/ACL, logs, suporte e também dados
condominiais/financeiros fora do escopo WEB/CMS.

O dump foi importado somente em `connect_legacy`, no container
`connect-erp-legacy-db-1` e volume `connect-erp_connect_legacy_db_data`.
`connect_erp` não foi alterado. Resultado: 53 tabelas, charset `utf8mb4`,
collation `utf8mb4_unicode_ci`; `mariadb-check` aprovou todas.

Conteúdo WEB/CMS permanece nas tabelas de settings, páginas, posts, categorias,
slides, brief, propostas, suporte e mídia. `users`, `access_*`, `app_log` e
`system_audit_logs` foram reutilizadas, não duplicadas. Tabelas `app_*` de
condomínios/financeiro foram preservadas somente no banco legado isolado e não
incorporadas ao ERP.

O dump contém dados pessoais e hashes/credenciais históricas; não foi
versionado, e relatórios não reproduzem registros. Tokens e integrações externas
permanecem desativados até rotação/validação.
