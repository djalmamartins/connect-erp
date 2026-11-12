<!-- markdownlint-disable MD024 -->
# 0247 — Baixa em lote dos agendamentos de mudança com a data já passada

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - ADR 0051 (cadastros operacionais: AP-120 agendamentos de mudança), só como cadastro reusado;
  - 0206 (saída de moradores da unidade: as saídas realizadas são apontadas para ela);
  - 0241 (mesmo desenho: baixa pela data que já está no cadastro, tudo ou nada, prévia e auditoria).

## Contexto

O cadastro de agendamentos de mudança (AP-120) tem as situações "Agendada", "Realizada" e "Cancelada", mas a portaria
raramente volta ao formulário depois da mudança. O agendamento de março segue "Agendada":

- aparece no relatório de mudanças (AP-095) como se ainda fosse acontecer;
- não se sabe quais saídas de fato aconteceram, nem se a unidade ainda tem inquilino cadastrado.

Busquei `erp_moves` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro e o relatório de mudanças usam a
tabela; nenhuma ação em lote trata dos agendamentos.

## Decisão

Rota nova: `POST /api/v1/moves/settle-past` com `{condominium_id, before?, outcome?, kinds?, move_ids?, reason,
dry_run?}`. O service é `MoveSettlement`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada agendamento é regravado pelo `RegistryService::save` do cadastro `moves`, só com a situação
  escolhida. Valem as regras e a auditoria `update` do formulário (unidade ativa, um agendamento por período e dia). A
  data, o período e os demais campos ficam como estão. Nada é apagado.
- **Resultado (`outcome`):** `done` (padrão) marca "Realizada"; `inactive` marca "Cancelada", para quem não compareceu.
- **Tipos:** os do cadastro (entrada e saída).
- **Nenhum aviso:** o cadastro não envia aviso.

### Quais agendamentos entram

- **Sem `move_ids`:** os agendamentos "Agendada" do condomínio com a data anterior a `before` (padrão hoje; nunca depois
  de hoje: a mudança de hoje ainda pode acontecer), dos tipos em `kinds` (padrão os dois). Os de unidade inativa ficam de
  fora, porque o formulário não os regrava; a contagem sai em `skipped_inactive_unit`.
- **Com `move_ids`:** só os informados. A regra pura `problem()` recusa o agendamento:
  - não encontrado no condomínio (inclusive excluído);
  - já realizado ou cancelado;
  - com a data ainda por vir (o próprio dia do corte não conta como passado);
  - de unidade inativa (corrija no cadastro).

### Gravação

- **Tudo ou nada:** numa transação com os agendamentos travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` (ou os erros do formulário) e `_move`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **`move_outs_to_review`:** com `outcome` `done`, as unidades de uma saída baixada que ainda têm inquilino ou morador
  ativo no cadastro. Os vínculos não são tocados: ficam apontados para a saída de moradores da unidade (0206).
- **Auditoria:** a `update` do formulário e um registro `settle` por agendamento (tipo, data, período, dias desde a
  data, resultado e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 agendamentos por vez.
- **Nenhum aviso é enviado** ao morador. Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.moves.view` e `erp.moves.manage`, as mesmas do cadastro; sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, tipo, data, período e dias desde a data. Não saem morador, unidade ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\MoveSettlementTest`:**
  - a regra pura, com o dia anterior ao corte, o próprio dia, o realizado, o cancelado e a unidade inativa; os tipos;
  - validação (corte, resultado, tipos, motivo, ids) e escopo (404);
  - as recusas antes do formulário (realizado, data por vir, unidade inativa, de outro condomínio, excluído,
    inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, mantém a data, não toca os vínculos, sem aviso e sem dado pessoal; controller e
    rota.
- **`Integration\MoveSettlementTest`** (MariaDB, banco `*_test`):
  - agendamentos gravados pelo cadastro: saída e entrada passadas, futura, de hoje e um de unidade depois inativada,
    com inquilino ativo na unidade da saída;
  - a prévia com os dias desde a data, o de unidade inativa de fora e a saída a revisar; o filtro por tipo; a prévia
    como Cancelada sem saída a revisar;
  - a baixa com a data mantida, os demais e o inquilino intactos; as auditorias `settle` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem morador na resposta;
  - os ids com um agendamento futuro desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista de agendamentos pode ganhar "Dar baixa nos passados"; quando ganhar, vem o
  artigo da Central de Ajuda.
