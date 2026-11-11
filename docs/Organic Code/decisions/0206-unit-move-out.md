# 0206 — Saída de moradores da unidade

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0090 (convite do morador), 0185 (qualidade do cadastro de unidades e moradores), ADR-002 (escopo)

## Contexto

Quando um inquilino entrega o apartamento ou a unidade é vendida, a administradora precisa:

- encerrar o vínculo do inquilino e dos moradores da casa;
- tirar o carro dele da lista da portaria e o animal do cadastro;
- garantir que o convite do portal que ele não usou deixe de valer.

Hoje são quatro cadastros, um registro por vez. O que fica esquecido aparece depois:

- a portaria libera um carro que não é mais da unidade;
- o ex-inquilino recebe comunicado;
- o convite pendente continua válido por dias.

## Decisão

Rota nova: `POST /api/v1/units/move-out` com
`{condominium_id, unit_id, relationships[]?, ends_on, reason, include_vehicles?, include_pets?, dry_run?}`. O service é
`UnitMoveOut`. A rota fica antes do laço dos cadastros.

### O que acontece

- **Vínculos** (cadastro `residents`) ativos da unidade com os tipos escolhidos:
  - padrão: inquilino e morador;
  - proprietário e responsável financeiro só se pedidos;
  - cada um regravado pelo `RegistryService::save` só com `ends_on` (a data da saída, até hoje) e situação inativa;
  - vale a regra do formulário: o fim não pode ser antes do início.
- **Convites pendentes** do portal do morador (ADR 0090) dessas pessoas: revogados.
- **Veículos e animais** da unidade: inativados pelo formulário, só com `include_vehicles` e `include_pets`.
- **Nada é apagado e nada é enviado:** sem e-mail, sem aviso e sem `NotificationOutbox`. A quebra de acordo, por
  exemplo, avisaria a unidade, por isso ficou fora deste ciclo.

### Avisos (não impedem)

- **`unit_without_residents`:** a unidade fica sem nenhum vínculo ativo.
- **`open_debt`:** a unidade tem receitas vencidas em aberto, com quantidade e valor. A dívida é da unidade e continua.

### Gravação

- **Tudo ou nada:** numa transação com os registros travados (`FOR UPDATE`), um registro recusado pelo formulário
  desfaz todos. O 422 traz os erros, `_registry` e `_record`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Unidade sem vínculo ativo** dos tipos escolhidos dá 422.
- **Auditoria:** a `update` do formulário em cada registro, a dos convites revogados e um registro `move_out` na
  unidade (data, motivo, tipos e quantidades), só quando grava.

### Acesso

- **Escopo (ADR-002):** condomínio fora do escopo dá 404; unidade de outro condomínio, excluída ou inexistente também
  dá 404.
- **Permissão:** exige `erp.access`, `erp.residents.view` e `erp.residents.manage`. Com `include_vehicles`, também
  `erp.vehicles.manage`; com `include_pets`, também `erp.pets.manage`. Sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

### Dados expostos

Saem só ids, tipos de vínculo e datas. Nenhum nome, documento, e-mail, telefone, placa ou nome de animal.

## Consequências

- **Testes.**
  - `Unit\UnitMoveOutTest` (SQLite):
    - tipos de vínculo e data da saída (regras puras);
    - validação, escopo (404 do condomínio e da unidade);
    - contrato do service, sem `NotificationOutbox`;
    - permissões do controller e rota.
  - `Integration\UnitMoveOutTest` (MariaDB, banco `*_test`):
    - prévia sem gravar e sem dado pessoal;
    - saída do inquilino e da moradora, com a proprietária mantida;
    - veículo e animal inativados e convite revogado;
    - auditoria `move_out` e nenhum aviso na outbox;
    - fim antes do início recusado, com tudo ou nada;
    - aviso `unit_without_residents`.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Acesso já aceito no portal:** a conta de quem já aceitou o convite depende da situação do vínculo, que fica
  inativa. Revogar conta de usuário é da frente de login/Studio, fora deste patch.
- **Sem tela nova neste patch.** Quando a tela existir (botão "Registrar saída" na unidade), ela ganha o artigo da
  Central de Ajuda.
