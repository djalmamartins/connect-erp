# Migrations do Connect ERP

Executadas por `service/commands/database-migrate.php` (`composer db:status`, `composer db:migrate`, `composer db:rollback`).

- Arquivo de avanço: `AAAAMMDD_NNNN_descricao.sql`; reversão obrigatória ao lado: `AAAAMMDD_NNNN_descricao.down.sql`.
- Ordem alfabética; arquivo aplicado não pode ser alterado (checksum).
- InnoDB, `utf8mb4_unicode_ci`, FKs, índices, `DECIMAL` para dinheiro.
- Não criar tabelas de usuários, permissões ou logs: reutilizar `users` e `access_*` (ADR-001).
- Dados de condomínio sempre com `condominium_id` (ADR-002).
- Nenhuma migration roda automaticamente em produção; `rollback` exige `--confirm-production` em produção.
- A suíte de testes aplica todos os arquivos de avanço no banco `_test` (teste de instalação limpa).
