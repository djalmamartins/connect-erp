<!-- markdownlint-disable MD024 -->
# 0249 — Vínculo em lote das cópias de cheques aos lançamentos pagos

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - ADR 0050 (cadastros de documentos: AP-127 cópias de cheques), só como cadastro reusado;
  - 0214 (conferência das cópias de cheques: aponta os cheques sem lançamento, `no_entry`);
  - 0245 (mesma regravação pelo cadastro com o valor em reais).

## Contexto

O cadastro de cópias de cheques (AP-127) liga cada cheque, opcionalmente, ao lançamento pago com ele. A conferência
(0214) aponta os cheques emitidos sem lançamento, mas a ligação é cheque por cheque no formulário, procurando o
lançamento na lista. Num talão inteiro de portaria, limpeza e manutenção, isso vira dezenas de buscas.

Busquei `erp_check_copies` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro e a conferência usam a
tabela; nenhuma ação em lote liga os cheques aos lançamentos.

## Decisão

Rota nova: `POST /api/v1/check-copies/link-entries` com `{condominium_id, window_days?, check_copy_ids?, reason,
dry_run?}`. O service é `CheckCopyMatching`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada cheque é regravado pelo `RegistryService::save` do cadastro `check_copies`, só com o lançamento
  (`entry_id`) preenchido. Valem as regras e a auditoria `update` do formulário (conta bancária ativa, imagem no Disco
  Virtual, lançamento ativo do mesmo condomínio). O valor vai em reais, como o formulário lê, e os demais campos ficam
  como estão.
- **O lançamento não é tocado.** Nada é apagado.
- **A conferência (0214)** deixa de apontar `no_entry` para o cheque ligado.

### Quando um lançamento confere

A regra pura `candidates()` aceita o lançamento quando:

- é despesa ativa e paga;
- foi paga pela mesma conta bancária do cheque, com o mesmo valor;
- foi paga do dia do cheque até `window_days` depois (padrão 30, de 0 a 120);
- ainda não está ligada a outra cópia de cheque, em qualquer situação.

A regra pura `pair()` só aceita o par sem dúvida: o cheque com um único lançamento que confere, e esse lançamento sem
outro cheque do lote que também confira. Os demais ficam como estão, para ligar pelo formulário:

- `skipped_no_match`: nenhum lançamento confere;
- `skipped_ambiguous`: mais de um lançamento confere, ou o mesmo lançamento confere com outro cheque.

### Quais cheques entram

- **Sem `check_copy_ids`:** os cheques emitidos e sem lançamento do condomínio. Ficam de fora os de conta bancária
  inativa ou imagem removida, que o formulário não regrava; a contagem sai em `skipped_inactive_link`.
- **Com `check_copy_ids`:** só os informados. A regra pura `problem()` recusa o cheque:
  - não encontrado no condomínio (inclusive excluído);
  - cancelado;
  - já ligado a um lançamento;
  - de conta bancária inativa ou imagem removida (corrija no cadastro).
  Um cheque sem par ou com dúvida também recusa o lote.

### Gravação

- **Tudo ou nada:** numa transação com os cheques travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  (ou os erros do formulário) e `_check`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `link_entry` por cheque (lançamento, valor, dias até o pagamento,
  prazo e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 cheques por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.check_copies.view`, `erp.check_copies.manage` e `erp.entries.view` (o cadastro
  e a conferência das cópias de cheques); sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, número, data e valor do cheque, o id do lançamento, a data do pagamento e os dias até ele. Não
  saem favorecido (pode ser pessoa física), descrição ou documento.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\CheckCopyMatchingTest`:**
  - a regra pura dos candidatos: o próprio dia, o fim do prazo, um dia depois, antes do cheque, outro valor, outra conta e
    o prazo 0;
  - a regra pura do par: o par único, sem par, dois cheques no mesmo lançamento e um cheque com dois lançamentos;
  - validação (prazo, motivo, ids) e escopo (404);
  - as recusas antes do formulário (cancelado, já ligado, conta inativa, imagem removida, de outro condomínio, excluído,
    inexistente, sem par, com dúvida), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, valor em reais, não toca o lançamento, sem aviso e sem favorecido; controller e
    rota.
- **`Integration\CheckCopyMatchingTest`** (MariaDB, banco `*_test`):
  - cheques e despesas gravados pelo cadastro: o par único, dois cheques iguais, sem par, lançamento já ligado e imagem
    removida;
  - a prévia com o par e as três contagens de fora;
  - o vínculo com o valor mantido, os demais e o lançamento intactos; as auditorias `link_entry` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem favorecido;
  - a conferência (0214) sem o cheque ligado;
  - os ids com um cheque sem par desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A conferência das cópias de cheques pode ganhar "Ligar os que conferem"; quando ganhar,
  vem o artigo da Central de Ajuda.
