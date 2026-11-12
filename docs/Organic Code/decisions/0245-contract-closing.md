<!-- markdownlint-disable MD024 -->
# 0245 — Encerramento em lote dos contratos com a vigência terminada

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0051 (cadastros operacionais: AP-119 contratos), só como cadastro reusado;
  - 0217 (renovação de contratos em lote: estende os que continuam);
  - 0239 (mesma checagem de fornecedor ativo e documento no Disco Virtual);
  - 0204 (reajuste dos lançamentos fixos).

## Contexto

O cadastro de contratos (AP-119) tem a situação "Encerrado", mas ela só muda quando alguém abre o formulário. O contrato
de portaria trocado em junho segue "Vigente":

- entra no painel operacional como vencido (`contracts_expired`) e no calendário de obrigações;
- soma no valor mensal contratado do condomínio.

A renovação em lote (0217) estende os contratos que continuam. Busquei `erp_contracts` em `source/`, no README das ADRs e
nos handoffs 02xx: só o cadastro, a renovação, o painel operacional e o calendário de obrigações usam a tabela; nenhuma
ação fecha os contratos que acabaram.

## Decisão

Rota nova: `POST /api/v1/contracts/close-ended` com `{condominium_id, before?, contract_ids?, reason, dry_run?}`. O
service é `ContractClosing`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada contrato é regravado pelo `RegistryService::save` do cadastro `contracts`, só com a situação
  "Encerrado". Valem as regras e a auditoria `update` do formulário. O fim, o valor mensal (repassado em reais, como o
  formulário lê) e os demais campos ficam como estão. Nada é apagado.
- **Fornecedor e documento:** a mesma checagem do cancelamento de empenhos (0239).
- **Nenhum aviso:** o cadastro não envia aviso.

### Quais contratos entram

- **Sem `contract_ids`:** os contratos vigentes do condomínio com fim anterior a `before` (padrão hoje; nunca depois de
  hoje). Ficam de fora os de fornecedor inativo ou documento removido, que o formulário não regrava; a contagem sai em
  `skipped_inactive_link`.
- **Com `contract_ids`:** só os informados. A regra pura `problem()` recusa o contrato:
  - não encontrado no condomínio (inclusive excluído);
  - já encerrado ou cancelado;
  - de prazo indeterminado (sem fim);
  - com a vigência ainda valendo (o próprio dia vale);
  - de fornecedor inativo ou documento removido (corrija no cadastro).

### Gravação

- **Tudo ou nada:** numa transação com os contratos travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` (ou os erros do formulário) e `_contract`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **`monthly_cents`:** a soma do valor mensal dos encerrados.
- **`recurring_to_review`:** os lançamentos fixos vigentes do condomínio com o fornecedor de um contrato encerrado (sem fim
  ou com a última competência a partir do mês atual). Eles não são tocados: só ficam apontados para revisão no reajuste e
  na geração do mês (0204).
- **Auditoria:** a `update` do formulário e um registro `close` por contrato (fim, dias desde o fim, valor mensal e
  motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 contratos por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.contracts.view` e `erp.contracts.manage`, as mesmas do cadastro e da renovação;
  sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, fim, dias desde o fim e valor mensal. Não saem objeto, fornecedor, documento ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ContractClosingTest`:**
  - a regra pura, com o dia anterior ao corte, o próprio dia, o encerrado, o cancelado, o indeterminado e o fornecedor;
  - validação (corte, motivo, ids) e escopo (404);
  - as recusas antes do formulário (encerrado, indeterminado, vigente, fornecedor inativo, documento removido, de outro
    condomínio, excluído, inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, valor em reais, mantém o fim, não toca os fixos, sem aviso e sem dado do
    contrato; controller e rota.
- **`Integration\ContractClosingTest`** (MariaDB, banco `*_test`):
  - contratos gravados pelo cadastro: terminados, vigente, indeterminado e um de fornecedor depois inativado;
  - a prévia com os dias desde o fim, a soma mensal, o de fornecedor inativo de fora e o lançamento fixo a revisar;
  - o encerramento com o fim e o valor mantidos, os demais e os fixos intactos; as auditorias `close` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem dado do contrato;
  - os ids com um contrato vigente ou indeterminado desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista de contratos pode ganhar "Encerrar os terminados"; quando ganhar, vem o artigo
  da Central de Ajuda.
