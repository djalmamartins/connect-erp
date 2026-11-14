<!-- markdownlint-disable MD024 -->
# 0269 — Inativação em lote dos animais de unidades sem morador ativo

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0206 (saída de moradores da unidade, que inativa animais só quando pedido, uma unidade por vez);
  - 0236 (resumo dos animais e veículos);
  - 0241 (encerramento dos vínculos vencidos);
  - 0265 (regra de morador ativo) e 0267 (inativação em lote dos veículos, mesmo desenho);
  - cadastro de animais (AP-013), só como cadastro reusado.

## Contexto

O cadastro de animais de estimação (AP-013) guarda os animais por unidade, para a portaria, as regras de convivência e
as campanhas de vacinação.

A saída da unidade (ADR 0206) só inativa os animais quando pedido, unidade por unidade. O encerramento dos vínculos
vencidos (ADR 0241) não toca os animais. Ficam então animais "Ativo" de quem já não mora ali. O resumo de animais e
veículos (ADR 0236) e as campanhas de vacinação contam quem saiu.

A inativação em lote dos veículos (ADR 0267) deixou esta ação como próximo passo. Busquei `erp_pets` em `source/`, no
README das ADRs e nos handoffs 02xx: usam a tabela o cadastro, o resumo, o painel, os dados de exemplo e a saída da
unidade. Nenhum confere o condomínio inteiro.

## Decisão

Rota nova: `POST /api/v1/pets/deactivate-unattached {condominium_id, pet_ids?, reason, dry_run?}`, logo depois de
`GET /reports/resident-portal` e antes do laço dos cadastros (`/pets/{id}`). O service é `PetUnitRelease`, em
`Source\Services\Erp`.

### Reuso

- **Gravação:** cada animal é regravado pelo `RegistryService::save` do cadastro "pets", só com a situação "Inativo", como
  na inativação dos veículos (0267). Valem as regras e a auditoria update do formulário.
- **Morador ativo:** a regra do 0265: vínculo ativo, sem excluído e sem fim de vínculo passado.
- **Erro do formulário antes de gravar:** `VehicleUnitRelease::formError` (a regra pura `RegistryValidator::validate`). A
  mensagem leva só o rótulo do campo e o texto do formulário, nunca o valor.
- **Sem aviso:** nada vai ao morador. Para reativar, o formulário.

### Quais entram

Entram os animais ativos do condomínio vinculados a uma unidade (`unit_id`) que não tem nenhum morador ativo, ou só os
dos `pet_ids` informados.

### Regra pura `problem()`

Recusa o animal:

- não encontrado no condomínio;
- já inativo;
- sem unidade vinculada, isto é, só com o texto da unidade: vincule no cadastro;
- cuja unidade ainda tem morador ativo;
- cuja unidade está inativa: o formulário não regrava;
- com erro no formulário (espécie fora da lista, por exemplo): corrija no cadastro.

Sem ids, os que ficam de fora são contados:

- `skipped_unlinked`: sem unidade vinculada;
- `skipped_inactive_unit`: unidade inativa;
- `skipped_invalid`: erro no formulário.

Com ids, um recusado desfaz todos (`_form` e `_pet`).

### Execução

- **`by_species`:** quantos dos inativados por espécie do cadastro, sempre todas as espécies.
- **Tudo ou nada:** uma transação com os animais travados (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `deactivate_unattached` por animal (espécie e motivo), só quando
  grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.pets.view` e `erp.pets.manage`, as do cadastro, e `erp.residents.view`, porque o
  morador ativo vem do cadastro de moradores. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou animal recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id e espécie do animal e as contagens. Não saem nome, raça, porte, tutor, vacinação, unidade ou
  observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\PetUnitReleaseTest`:**
  - a regra pura e o erro do formulário sem o valor do cadastro;
  - a validação e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens dos que ficam de fora (sem vínculo, unidade inativa, espécie fora da lista);
  - as espécies lidas do cadastro, sem lista fixa no teste;
  - o morador com fim de vínculo passado, inativo ou excluído contando como unidade vazia;
  - o contrato de reuso, o controller e a rota.
- **`Integration\PetUnitReleaseTest`** (MariaDB, banco `*_test`):
  - a prévia e a inativação pelo cadastro do cão e do gato da unidade vazia;
  - o animal da unidade com morador fica;
  - só a situação muda (a vacinação continua);
  - as auditorias `deactivate_unattached` e update;
  - nenhum aviso e nenhum dado do animal;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela de animais pode ganhar o botão "Inativar os
  animais da unidade vazia"; quando ganhar, vem o artigo.
