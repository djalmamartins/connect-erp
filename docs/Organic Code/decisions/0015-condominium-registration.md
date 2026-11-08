# 0015 — Cadastro de condomínios no ERP

Data: 2026-11-08 · Status: aceita

## Contexto

Os cadastros do ERP (ADR 0014) pertencem a um condomínio ativo. Sem condomínio cadastrado, nenhum
cadastro pode ser salvo, e até 2026-11-08 a única forma de criar um era um INSERT manual no banco.
A Connect é a única administradora (ADR-002): a carteira de condomínios é dela, não de cada usuário.

## Decisão

- Telas `GET /condominios/novo`, `POST /condominios`, `GET /condominios/{id}/editar` e
  `POST /condominios/{id}`, servidas por `Condominiums` e `Source\Services\Erp\CondominiumService`.
- Cadastrar ou alterar exige `erp.condominiums.manage` **e** acesso global explícito
  (`erp.condominiums.all` ou papel developer, via `CondominiumScope::hasGlobalAccess`). Quem só tem
  vínculo com alguns condomínios consulta, mas não cria nem altera.
- Campos: nome (obrigatório, até 160), CNPJ (opcional, validado, único) e situação.
- Não há exclusão: um condomínio que sai da carteira fica `inactive`. Inativos somem do escopo dos
  cadastros, mas continuam visíveis e editáveis para quem administra a carteira, para reativação.
- POST com CSRF e PRG; SQL parametrizado; criação e alteração gravadas em `system_audit_logs`.

## Consequências

- Os demais dados do condomínio (unidades, contas, configurações) continuam aguardando a ficha AP-004.
- Vincular usuários a condomínios (`user_condominium_access`) ainda não tem tela.
