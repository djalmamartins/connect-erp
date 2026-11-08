# 0017 — Unidades (AP-006) e troca de unit_label por unit_id

Data: 2026-11-08 · Status: aceito (decidido pela thread de backend, por delegação do Djalma; aguarda integração no container)

Numeração: o coordenador indicou 0016, mas o `claude-dev` já usou 0016 (mapa do código)
depois que o `claude-units` foi criado. Os comentários antigos "ADR 0040" e "ADR 0016" no código
desta frente se referem a este ADR.

## Contexto

O ADR 0014 deixou a unidade como texto livre (`unit_label`) em proprietários e inquilinos,
síndico e conselho, veículos, visitantes e animais, "até existir o módulo Unidades". A página
AP-006 (`/condominios/unidades`) já existia no catálogo, sem dados. A ficha AP-006 segue com
campos PENDENTES (auditoria somente leitura). Os campos abaixo são PROPOSTOS.

## Decisão

- **Unidades usam o motor único de cadastros** (`Definitions` → `RegistryService`), não um
  serviço próprio. As regras específicas viraram recursos genéricos do motor:
  - `unique` com `nullable`: bloco vazio conta como valor (`101` sem bloco não repete);
  - tipo `decimal` (`scale`, `min`, `max_value`) para fração ideal (0 a 1, 6 casas) e área
    privativa (m², 2 casas);
  - `computed`: coluna gerada pelo banco devolvida na API (`label`);
  - `referenced_by`: exclusão recusada (`RegistryInUse`, 409 na API) enquanto houver
    cadastro ativo apontando para a unidade;
  - `group`: Unidades fica no grupo Condomínios do catálogo (os demais seguem em Cadastros).
- **Tabela `erp_units`** (migration `20261108_0401`): condomínio, bloco, número, tipo
  (apartamento, casa, sala, loja, garagem, outro), andar, fração ideal, área privativa,
  observações, situação, exclusão lógica. `label` é gerado (`Bloco - Número`).
- **Permissões** `erp.units.view` e `erp.units.manage` (migration `0403`), para `developer`
  e `super_admin`. Prefixo `erp.` nega sem registro (ADR 0006).
- **Troca gradual para `unit_id`** (migration `0402`), nas cinco tabelas:
  1. `unit_id` nullable com FK para `erp_units` (`ON DELETE RESTRICT`);
  2. cria uma unidade por texto distinto e condomínio (sem bloco, número = texto, tipo
     `outro`, com observação pedindo revisão);
  3. liga cada registro à unidade de mesmo texto.
  - `unit_label` **não é alterado nem removido**. Com unidade escolhida, o serviço grava
    em `unit_label` o texto da unidade. Sem unidade, o texto continua aceito (e liga sozinho
    se houver unidade com o mesmo texto). Onde a unidade era obrigatória, agora é obrigatório
    escolher a unidade ou digitar o texto.
  - Remover `unit_label` exige outra migration, depois de validar no lab que todo registro
    ativo tem `unit_id`.
- **Escopo**: a unidade escolhida precisa estar ativa e ser do mesmo condomínio do registro.
  A lista de unidades do formulário só traz condomínios do escopo do usuário.
- **Tela**: o campo `unit` é um select agrupado por condomínio (`components/forms/registry-field.php`).
  Listagem, formulário, histórico e API vêm do motor, sem template novo.

## Consequências

- Reversão: `.down` da 0402 remove só `unit_id` (nada se perde, o texto foi mantido); `.down`
  da 0401 remove as unidades; `.down` da 0403 remove as permissões.
- Unidades criadas pela 0402 têm o texto antigo como número: revisar bloco e tipo no lab.
- `docs/api/registries.openapi.yaml` regenerado (recurso `/units`, `unit_id` nos cinco cadastros).
- Testes: `tests/legacy/Unit/UnitDefinitionTest.php`, `RegistryValidatorTest` (definições x
  migrations generalizado para todas as migrations) e `tests/legacy/Integration/UnitRegistryTest.php`
  (unicidade com e sem bloco, vínculo e cópia do texto, unidade de outro condomínio, exclusão em
  uso, escopo, backfill idempotente).
