<!-- markdownlint-disable MD024 -->
# 0267 — Inativação em lote dos veículos de unidades sem morador ativo

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0206 (saída de moradores da unidade, que inativa veículos só quando pedido, uma unidade por vez);
  - 0241 (encerramento dos vínculos vencidos);
  - 0265 (bloqueio em lote dos visitantes de unidades sem morador ativo, mesmo desenho);
  - cadastro de veículos (AP-011), só como cadastro reusado.

## Contexto

O cadastro de veículos (AP-011) guarda os veículos autorizados por unidade, para a portaria e o controle de vagas.

A saída da unidade (ADR 0206) só inativa os veículos quando pedido, unidade por unidade. O encerramento dos vínculos
vencidos (ADR 0241) acerta os moradores e não toca os veículos. Ficam então veículos "Ativo" de quem já não mora ali: a
portaria abre a garagem para a placa e a vaga segue ocupada no cadastro.

Busquei `erp_vehicles` em `source/`, no README das ADRs e nos handoffs 02xx. Usam a tabela o cadastro, o resumo de
animais e veículos, o painel, os dados de exemplo e a saída da unidade (0206). Nenhum confere o condomínio inteiro.

## Decisão

Rota nova: `POST /api/v1/vehicles/deactivate-unattached {condominium_id, vehicle_ids?, reason, dry_run?}`, logo depois de
`GET /reports/visitor-units` e antes do laço dos cadastros (`/vehicles/{id}`). O service é `VehicleUnitRelease`, em
`Source\Services\Erp`.

### Reuso

- **Gravação:** cada veículo é regravado pelo `RegistryService::save` do cadastro "vehicles", só com a situação "Inativo",
  como no bloqueio em lote dos visitantes (0265). Valem as regras e a auditoria update do formulário.
- **Morador ativo:** a mesma regra do 0265: vínculo ativo, sem excluído e sem fim de vínculo passado.
- **Erro do formulário antes de gravar:** a regra pura `RegistryValidator::validate`, como nas importações. Ela aponta o
  cadastro antigo que o formulário não regrava (placa fora do padrão, por exemplo). A mensagem leva só o rótulo do campo
  e o texto do formulário, nunca o valor.
- **Sem aviso:** nada vai ao morador. Para reativar, o formulário.

### Quais entram

Entram os veículos ativos do condomínio vinculados a uma unidade (`unit_id`) que não tem nenhum morador ativo, ou só os
dos `vehicle_ids` informados.

### Regra pura `problem()`

Recusa o veículo:

- não encontrado no condomínio;
- já inativo;
- sem unidade vinculada, isto é, só com o texto da unidade: vincule no cadastro;
- cuja unidade ainda tem morador ativo;
- cuja unidade está inativa: o formulário não regrava;
- com erro no formulário: corrija no cadastro.

Sem ids, os que ficam de fora são contados:

- `skipped_unlinked`: sem unidade vinculada;
- `skipped_inactive_unit`: unidade inativa;
- `skipped_invalid`: erro no formulário.

Com ids, um recusado desfaz todos (`_form` e `_vehicle`).

### Execução

- **`by_kind`:** quantos dos inativados por tipo do cadastro (carro, moto, utilitário, outro), sempre os quatro.
- **Tudo ou nada:** uma transação com os veículos travados (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `deactivate_unattached` por veículo (tipo e motivo), só quando
  grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.vehicles.view` e `erp.vehicles.manage`, as do cadastro, e `erp.residents.view`,
  porque o morador ativo vem do cadastro de moradores. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou veículo recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id e tipo do veículo e as contagens. Não saem placa, modelo, cor, vaga, responsável, unidade ou
  observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VehicleUnitReleaseTest`:**
  - a regra pura e o erro do formulário sem o valor do cadastro;
  - a validação e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens dos que ficam de fora (sem vínculo, unidade inativa, placa fora do padrão);
  - o morador com fim de vínculo passado, inativo ou excluído contando como unidade vazia;
  - o contrato de reuso, o controller e a rota.
- **`Integration\VehicleUnitReleaseTest`** (MariaDB, banco `*_test`):
  - a prévia e a inativação pelo cadastro do carro e da moto da unidade vazia;
  - o veículo da unidade com morador fica;
  - as auditorias `deactivate_unattached` e update;
  - nenhum aviso e nenhum dado do veículo;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. Os animais (AP-013) podem ganhar a mesma ação; a
  tela de veículos pode ganhar o botão "Inativar os veículos da unidade vazia", e quando ganhar vem o artigo.
