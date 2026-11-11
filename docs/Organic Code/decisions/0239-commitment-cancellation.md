<!-- markdownlint-disable MD024 -->
# 0239 — Cancelamento em lote dos empenhos em aberto há muito tempo

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0050 (cadastros de documentos: AP-128 empenhos), só como cadastro reusado;
  - 0238 (posição dos empenhos: mostra a idade dos em aberto);
  - 0233 (mesmo desenho: destino em lote pelo cadastro com prazo, tudo ou nada, prévia e auditoria).

## Contexto

A posição dos empenhos (0238) mostra quanto está empenhado e não liquidado há mais de 60 ou 90 dias: reservas de despesa
que não vão mais acontecer (orçamento recusado, obra desistida) e que seguem inflando o valor empenhado. Hoje cada uma é
cancelada à mão no formulário. Busquei `erp_commitments` em `source/`, no README das ADRs e nos handoffs 02xx: só o
cadastro, a inativação de fornecedores e o relatório 0238 usam a tabela; nenhuma ação em lote cancela empenhos.

## Decisão

Rota nova: `POST /api/v1/commitments/cancel-stale` com `{condominium_id, older_than_days?, commitment_ids?, reason,
dry_run?}`. O service é `CommitmentCancellation`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada empenho é regravado pelo `RegistryService::save` do cadastro `commitments`, só com a situação
  "Cancelado". Valem as regras, as referências (fornecedor e documento) e a auditoria `update` do formulário; os demais
  campos, inclusive o valor, ficam como estão. Nada é apagado.
- **Mesmo desenho da 0233:** prazo em dias, ids opcionais, regra pura, tudo ou nada, prévia e auditoria.
- **Nenhum aviso:** o cadastro não envia aviso.

### Quais empenhos entram

- **Sem `commitment_ids`:** os empenhos em aberto do condomínio feitos há mais de `older_than_days` dias (padrão 90, de 30
  a 3650), ou seja, com `committed_on` até `committed_until`. Ficam de fora, com a contagem na resposta:
  - os com lançamento ligado (`skipped_with_entry`): o pagamento aconteceu, liquide no cadastro;
  - os de fornecedor inativo ou documento removido (`skipped_inactive_link`): o formulário não os regrava.
- **Com `commitment_ids`:** só os informados. A regra pura `problem()` recusa o empenho:
  - não encontrado no condomínio (inclusive excluído);
  - que não está em aberto (liquidado ou cancelado);
  - com lançamento ligado;
  - ainda dentro do prazo;
  - de fornecedor inativo ou documento removido (corrija no cadastro).

### Gravação

- **Tudo ou nada:** numa transação com os empenhos travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  (ou os erros do formulário) e `_commitment`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim, com a soma do valor que seria cancelado.
- **Auditoria:** a `update` do formulário e um registro `cancel` por empenho (data, dias em aberto, valor e motivo), só
  quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 empenhos por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.commitments.view` e `erp.commitments.manage`, as mesmas do cadastro; sem uma
  delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, data, dias em aberto e valor. Não saem descrição, fornecedor, documento ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\CommitmentCancellationTest`:**
  - a regra pura, com o último dia do prazo, o lançamento ligado e o vínculo inativo;
  - validação (prazo, motivo, ids) e escopo (404);
  - as recusas antes do formulário (liquidado, dentro do prazo, com lançamento, fornecedor inativo, documento removido,
    de outro condomínio, excluído, inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, sem aviso e sem descrição nem fornecedor; controller e rota.
- **`Integration\CommitmentCancellationTest`** (MariaDB, banco `*_test`):
  - empenhos gravados pelo cadastro, com e sem fornecedor;
  - a prévia com os dias em aberto, o valor somado e o de fornecedor inativo de fora;
  - o cancelamento dos fora do prazo, com o recente e o de fornecedor inativo intactos e o valor preservado;
  - as auditorias `cancel` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem descrição nem fornecedor;
  - os ids com um empenho dentro do prazo desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista de empenhos pode ganhar "Cancelar os antigos"; quando ganhar, vem o artigo da
  Central de Ajuda.
