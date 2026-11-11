<!-- markdownlint-disable MD024 -->
# 0236 — Resumo dos animais de estimação e dos veículos

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-011 (veículos) e AP-013 (animais de estimação), só como fonte dos dados;
  - painel da unidade (`PanelSummaries`), que conta só os de uma unidade;
  - 0232 e 0234 (mesmo padrão de relatório: regra pura, totais iguais à soma das linhas).

## Contexto

Os cadastros de veículos e de animais de estimação listam registro por registro, com dono, placa e unidade. O painel da
unidade conta só os de uma unidade. O síndico e a administradora querem a foto do condomínio:

- quantos animais de cada espécie e porte, e quantos com a vacinação em dia;
- quantos veículos de cada tipo, e quantos sem vaga informada;
- quantas unidades têm um, dois ou três ou mais animais ou veículos.

Isso serve para regras de convivência, garagem e campanhas de vacinação. Busquei `erp_pets` e `erp_vehicles` em
`source/`, no README das ADRs e nos handoffs 02xx: só os cadastros e o painel da unidade usam as tabelas; nenhum relatório
resume o condomínio.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/pets-vehicles?condominium_id=`. O service é `PetsVehiclesSummary`, em
`Source\Services\Erp`.

### Reuso

- **Opções do cadastro:** `RegistryService::definition` (espécie, porte e tipo de veículo), com os mesmos rótulos.
- **Regra das listas:** só os ativos, sem os excluídos.
- **Unidade:** a vinculada (`unit_id`) ou, sem vínculo, o texto da unidade normalizado, só para contar unidades distintas;
  ela não sai na resposta.

### Regra pura `summarize()`

- **pets.rows:** uma linha por espécie (sempre as do cadastro), com `pets`, o porte (pequeno, médio, grande, sem porte)
  somando `pets`, e `vaccinated` e `not_vaccinated` somando `pets`. Espécie fora do cadastro conta em "outro".
- **vehicles.rows:** uma linha por tipo, com `vehicles`, `with_spot` e `without_spot` somando `vehicles` (vaga em branco
  conta como sem vaga). Tipo fora do cadastro conta em "outro".
- **Totais:** `pets.totals` e `vehicles.totals` são a soma das linhas.
- **units:** as unidades ativas do condomínio e, para animais e para veículos, quantas unidades têm 1, 2 ou 3 ou mais,
  somando as unidades com registro.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.pets.view` e `erp.vehicles.view`, as mesmas dos cadastros; sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Dados que saem:** só as opções e as contagens. A consulta nem lê nome, raça, placa, modelo, cor, dono, tutor ou
  observação; a vaga é lida só como "tem ou não tem".
- **Não grava e não envia nada.** Sem migration e sem tabela nova. Nenhum arquivo existente foi alterado além da rota e da
  OpenAPI.

## Consequências

- **`Unit\PetsVehiclesSummaryTest`:**
  - a regra pura: as opções do cadastro, o porte, a vacinação, a vaga, a espécie e o tipo fora do cadastro, a chave da
    unidade (vínculo e texto normalizado), as unidades por quantidade e os totais;
  - a montagem no SQLite: sem os inativos, os excluídos e os de outro condomínio, a vaga em branco, as unidades ativas e
    sem dado pessoal;
  - validação, escopo (404) e contrato (somente leitura, permissões e rota).
- **`Integration\PetsVehiclesSummaryTest`** (MariaDB, banco `*_test`): unidades, animais e veículos gravados pelos
  cadastros, os totais, as unidades por quantidade, nada na auditoria, sem dado pessoal e o escopo.
- **Sem tela nova neste patch.** A tela de relatórios pode ganhar "Animais e veículos"; quando ganhar, vem o artigo da
  Central de Ajuda.
