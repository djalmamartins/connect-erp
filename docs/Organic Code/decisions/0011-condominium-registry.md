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

- **Síndico (`manager_person_id`)**: nullable e sem FK, porque Pessoas ainda não existe. Na
  Fase 2, o síndico vira um vínculo pessoa↔condomínio com papel e período. A coluna será
  migrada para essa tabela e removida por migration própria. Até lá, não aparece no formulário.
- **Banco/agência/conta (`bank_code`, `bank_branch`, `bank_account`)**: nullable. No
  núcleo financeiro, contas bancárias viram uma entidade própria por condomínio (várias
  contas, carteira, convênio). Os valores serão migrados para lá e as colunas removidas.

## Consequências

- Registros antigos continuam válidos no banco. Na primeira edição, os campos obrigatórios
  precisam ser preenchidos.
- `claude-layout` precisa adicionar os botões de novo/editar e o estilo do formulário. As
  rotas novas entram antes de `/condominios/{id}` também no `routes.php` daquela frente.
- O bug do `is_document()` fica registrado para correção separada (ele é usado em login e cadastro de usuários).
