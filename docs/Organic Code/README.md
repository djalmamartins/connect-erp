# Organic Code — implantação

Este repositório será a base PHP/MovesCode reutilizável. A origem auditada é `djalmamartins/realizze` (somente leitura). O instalador inicial, certificado local e integrações ainda precisam de validação no Mac.

## Gates para declarar funcional

1. Migrations completas de usuários, tokens e permissões, verificadas em MySQL vazio.
2. Registro, confirmação de e-mail, login, logout e recuperação de senha testados.
3. TOTP 2FA com códigos de recuperação e proteção contra bypass.
4. Domínios locais e HTTPS testados no Docker sem interferir em outros projetos.
5. Testes automatizados, revisão de segurança e PR aprovado.

**Não tratar estrutura de arquivos como aplicativo funcional.**
