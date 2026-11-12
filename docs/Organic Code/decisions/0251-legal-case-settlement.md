<!-- markdownlint-disable MD024 -->
# 0251 — Encerramento em lote dos processos de cobrança com as contas quitadas

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - cobrança (Collections): abertura, andamento e encerramento do processo, só como regra reusada;
  - 0250 (carteira da cobrança judicial: mostra quanto das contas cobradas já foi pago).

## Contexto

A cobrança (Collections) encerra o processo pelo andamento, um a um (`addEvent` com `to_status = closed` e o motivo). A
carteira (0250) mostra quanto das contas cobradas já foi pago, mas o processo cujo devedor pagou tudo segue "Ajuizado" ou
"Suspenso" até alguém abrir o processo e registrar o encerramento.

Busquei `erp_legal_cases` em `source/`, no README das ADRs e nos handoffs 02xx: só a cobrança grava na tabela, processo a
processo, e a carteira (0250) só lê; nenhuma ação em lote encerra processos.

## Decisão

Rota nova: `POST /api/v1/legal-cases/close-settled` com `{condominium_id, case_ids?, reason, dry_run?}`. O service é
`LegalCaseSettlement`, em `Source\Services\Erp`.

### Reuso

- **Transição:** `Collections::canTransition` (encerrar é aceito de qualquer fase aberta).
- **Encerramento:** o mesmo de `Collections::addEvent`, feito aqui dentro de uma transação só para o lote (o `addEvent`
  abre a sua): a fase vai para "Encerrado", `closed_on` e `close_reason` são gravados e um andamento `closed` é registrado
  com a nota, a fase anterior e o usuário.
- **Nota:** "Encerrado em lote: todas as contas cobradas foram pagas." seguida do motivo.
- **As contas não são tocadas.** Nada é apagado.

### Quando o processo está quitado

A regra pura `settled()` só aceita o processo com contas ligadas e todas ativas e pagas. Ficam de fora, para encerrar pelo
andamento:

- `open`: conta cobrada ainda em aberto;
- `cancelled`: conta cancelada ou excluída, por exemplo trocada por acordo (a dívida pode seguir nas parcelas);
- `no_entries`: processo sem contas ligadas.

A regra pura `closingDate()` usa o pagamento da última conta como data do encerramento, nunca antes da abertura nem
depois de hoje.

### Quais processos entram

- **Sem `case_ids`:** os processos não encerrados do condomínio. Os que não estão quitados ficam de fora, com as contagens
  em `skipped_open`, `skipped_cancelled` e `skipped_no_entries`.
- **Com `case_ids`:** só os informados. A regra pura `problem()` recusa o processo não encontrado no condomínio, já
  encerrado, sem contas, com conta em aberto ou com conta cancelada.

### Gravação

- **Tudo ou nada:** numa transação com os processos travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  e `_case`.
- **Prévia:** `dry_run` passa por tudo, inclusive o andamento, e desfaz no fim; `recovered_cents` soma o recuperado.
- **Auditoria:** um registro `close_settled` por processo (fase anterior, data do encerramento, valor recuperado, contas e
  motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 processos por vez.
- **Nenhum aviso é enviado** ao devedor nem ao advogado.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.collections.view` e `erp.collections.manage`, as mesmas do andamento do
  processo; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, fase anterior, data do encerramento, valor recuperado e quantidade de contas. Não saem unidade,
  devedor, advogado, número do processo ou vara.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\LegalCaseSettlementTest`:**
  - as regras puras: quitado, em aberto, cancelado, sem contas e misto; as recusas; a data do encerramento no último
    pagamento, na abertura e até hoje;
  - a prévia completa em SQLite: os quitados, as três contagens de fora, o recuperado, a conta excluída como cancelada, e
    nada gravado no fim;
  - validação, escopo (404) e as recusas com ids, sem transação aberta;
  - contrato de reuso: transição, encerramento e andamento como no `addEvent`, sem tocar as contas, sem aviso e sem dado
    do processo; controller e rota.
- **`Integration\LegalCaseSettlementTest`** (MariaDB, banco `*_test`):
  - processos com contas do cadastro: quitado, com conta em aberto, com conta trocada por acordo e sem contas;
  - a prévia e o encerramento: a data do último pagamento, a nota, o andamento `closed` e a auditoria `close_settled`;
  - os demais intactos, nenhum aviso e a carteira (0250) com o encerrado;
  - os ids com conta em aberto recusados; o escopo.
- **Sem tela nova neste patch.** A lista de processos pode ganhar "Encerrar os quitados"; quando ganhar, vem o artigo da
  Central de Ajuda.
