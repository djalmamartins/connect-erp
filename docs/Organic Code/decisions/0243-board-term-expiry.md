<!-- markdownlint-disable MD024 -->
# 0243 — Encerramento em lote dos mandatos vencidos do síndico e do conselho

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0151 (e-mail e telefone obrigatórios no cadastro do síndico e do conselho);
  - calendário de obrigações e painel operacional (mostram os mandatos vencendo);
  - 0241 (mesmo desenho: encerramento pelo fim que já está no cadastro, tudo ou nada, prévia e auditoria).

## Contexto

O cadastro "Síndico e conselho" (AP-014) guarda o fim do mandato (`term_ends_on`), mas a situação só muda quando alguém
abre o formulário. Depois da assembleia que elege o novo corpo diretivo, o conselho anterior segue "ativo". Além disso, a
regra de um síndico ativo por condomínio (`single_active`) recusa o cadastro do síndico novo enquanto o antigo não for
inativado, um a um.

Busquei `erp_board_members` em `source/`, no README das ADRs e nos handoffs 02xx:

- o calendário de obrigações e o painel operacional só leem os mandatos;
- a implantação do condomínio só conta os membros;
- nenhuma ação em lote mexe no cadastro do síndico e do conselho.

## Decisão

Rota nova: `POST /api/v1/board-members/expire-terms` com `{condominium_id, before?, roles?, member_ids?, reason,
dry_run?}`. O service é `BoardTermExpiry`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada membro é regravado pelo `RegistryService::save` do cadastro `board`, só com a situação inativa. Valem
  as regras e a auditoria `update` do formulário. O fim do mandato e os demais campos ficam como estão. Nada é apagado.
- **Cargos:** os do cadastro (`RegistryService::definition('board')`).
- **Nenhum aviso:** o cadastro não envia aviso.

### Quais membros entram

- **Sem `member_ids`:** os membros ativos do condomínio com o fim do mandato anterior a `before` (padrão hoje; nunca
  depois de hoje), dos cargos em `roles` (padrão todos). Ficam de fora, com a contagem na resposta, os que o formulário
  não regrava:
  - os de unidade inativa (`skipped_inactive_unit`);
  - os sem e-mail ou telefone, cadastrados antes da ADR 0151 (`skipped_incomplete`).
- **Com `member_ids`:** só os informados. A regra pura `problem()` recusa o membro:
  - não encontrado no condomínio (inclusive excluído);
  - já inativo;
  - sem fim de mandato;
  - com o mandato ainda valendo (o próprio dia vale);
  - de unidade inativa;
  - sem e-mail ou telefone (complete no cadastro).

### Gravação

- **Tudo ou nada:** numa transação com os membros travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  (ou os erros do formulário) e `_member`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **`sindico_active_after`:** os síndicos ativos que restam no condomínio depois do encerramento; 0 indica que falta
  cadastrar o novo.
- **Auditoria:** a `update` do formulário e um registro `expire` por membro (cargo, fim, dias desde o fim e motivo), só
  quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 membros por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.board.view` e `erp.board.manage`, as mesmas do cadastro; sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, cargo, fim do mandato e dias desde o fim. Não saem nome, documento, contato ou unidade.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\BoardTermExpiryTest`:**
  - a regra pura, com o dia anterior ao corte, o próprio dia, a unidade inativa e o cadastro sem contato; os cargos;
  - validação (corte, cargos, motivo, ids) e escopo (404);
  - as recusas antes do formulário (inativo, sem fim, mandato valendo, unidade inativa, sem contato, de outro condomínio,
    excluído, inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, mantém o fim, sem aviso e sem dado pessoal; controller e rota.
- **`Integration\BoardTermExpiryTest`** (MariaDB, banco `*_test`):
  - membros gravados pelo cadastro, com mandatos vencidos e valendo, um de unidade depois inativada e um sem e-mail;
  - a prévia com os dias desde o fim, as duas contagens de fora e `sindico_active_after`; o filtro por cargo;
  - o encerramento com o fim mantido e os demais intactos; as auditorias `expire` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem dado pessoal;
  - o síndico novo cadastrado depois do encerramento;
  - os ids com um mandato valendo desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista do síndico e do conselho pode ganhar "Encerrar os mandatos vencidos"; quando
  ganhar, vem o artigo da Central de Ajuda.
