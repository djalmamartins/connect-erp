# 0011 — Cadastro e edição de condomínios (AP-004)

Data: 2026-11-08 · Status: aceito (aguarda testes de integração no container)

## Contexto

A fatia de Condomínios era só de leitura (ADR 0007), porque os campos da AP-004 estavam
PENDENTE. Djalma aprovou a lista de campos em 2026-11-08.

## Decisão

- A migration `20261108_0005_condominiums_registry` estende `condominiums` (ADD COLUMN IF
  NOT EXISTS, com reversão em `.down.sql`). Não há tabela nova.
- Validação pura em `Source\Services\CondominiumValidator`, incluindo um validador de CNPJ
  próprio. O helper existente `is_document()` aceita CNPJ com dígito verificador errado
  (ex.: `11222333000182`) e não foi usado.
- Escrita única em `Source\Services\CondominiumRegistry`: permissão
  `erp.condominiums.manage`, escopo na edição, CNPJ único (consulta + índice único) e
  auditoria via `Support/Audit`.
- Controller `Erp\Connect\Condominiums` com GET/POST de novo e editar, CSRF, limite de
  tentativas e redirecionamento após o POST. Formulário `pages/condominiums/form.php`.

### Colunas provisórias

- **Síndico**: a 0005 criou `manager_person_id`, mas o síndico já é o papel `sindico` em
  `erp_board_members` (migration `20261108_0201`, com mandato), e duas fontes para o mesmo
  dado se contradiriam. A 0005 não foi alterada, porque pode já ter sido aplicada. A coluna é
  removida pela `20261108_0006_drop_condominium_manager` (o `.down.sql` a recria nullable).
  Validator, formulário e testes deixam de usar o campo **no mesmo commit da 0006**: o
  `INSERT` lista as colunas de `CondominiumValidator::FIELDS`, e o código antigo falharia com
  a coluna já removida.
- **Banco/agência/conta (`bank_code`, `bank_branch`, `bank_account`)**: nullable. No
  núcleo financeiro, contas bancárias viram uma entidade própria por condomínio (várias
  contas, carteira, convênio). Os valores serão migrados para lá e as colunas removidas.

## Consequências

- Registros antigos continuam válidos no banco. Na primeira edição, os campos obrigatórios
  precisam ser preenchidos.
- `claude-layout` precisa adicionar os botões de novo/editar e o estilo do formulário. As
  rotas novas entram antes de `/condominios/{id}` também no `routes.php` daquela frente.
- O bug do `is_document()` foi corrigido em `1ee9cd8`.

## Merge com a `main` (2026-11-08)

- **Banco:** sem conflito com `0201`/`0202` (tabelas `erp_*` e permissões, que não alteram
  `condominiums`) nem com `0010`/`0011` (checklist de chegada). O runner aplica qualquer
  pendente sem exigir ordem, e todas as colunas usam `IF NOT EXISTS`/`IF EXISTS`.
- **Código:** o PR #18 (`12495b9`) já unificou o cadastro. A `main` não tem mais
  `Source\Services\Erp\CondominiumService`: o controller usa só `CondominiumRegistry`, com as rotas
  `GET|POST /condominios/novo` e `GET|POST /condominios/{id}/editar`. O plano de unificação
  anterior não é mais necessário.
