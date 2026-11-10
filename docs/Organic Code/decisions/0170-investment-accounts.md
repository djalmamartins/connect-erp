# 0170 — Aplicação financeira separada da conta corrente

Data: 2026-11-10 · Status: proposto para revisão · Modelagem decidida com a autorização do Djalma · Continua os ADRs 0167 e 0169

## Contexto

As contas bancárias já têm o tipo `aplicacao` (migration 20261108_0504), e já existe a transferência entre
contas do mesmo condomínio (AP-029, `erp_bank_transfers`). Ela não é receita nem despesa, não apaga nada e
pode ser cancelada. Faltava:

- ligar a aplicação à sua conta corrente;
- aplicar e resgatar;
- separar a aplicação no Disponível do balanço;
- mostrar a transferência dos dois lados num Razão das contas bancárias.

## Decisão

- **Sem tabela nova.** A migration 20261109_0707, com down, só acrescenta `erp_bank_accounts.parent_bank_account_id`:
  - é a conta corrente ligada à aplicação, com índice;
  - não tem FOREIGN KEY. O service valida que é uma conta corrente ativa do mesmo condomínio e que a conta
    ligada é de tipo `aplicacao`;
  - a ligação é auditada (`POST /api/v1/investments/{id}/link`, permissão `erp.bank_accounts.manage`).
- **Aplicar e resgatar** (`POST /api/v1/investments/{id}/apply|redeem`):
  - reaproveitam `EntryShortcuts::transfer` sem mudá-lo, com as mesmas validações (contas ativas do
    condomínio, valor, data até hoje e competência aberta) e as mesmas permissões (`erp.entries.manage`);
  - aplicar vai da conta corrente ligada para a aplicação; resgatar faz o caminho inverso;
  - sem vínculo, a resposta é 422.
- **Estorno**: é o cancelamento da transferência que já existe (`POST /api/v1/bank-transfers/{id}/cancel`).
  Tira a transferência dos saldos, guarda o motivo e não apaga nada.
- **Rendimento e IR/IOF**: o rendimento é receita e o IR/IOF é despesa, lançados normalmente (`erp_entries`)
  com a aplicação como conta bancária. Entram na DRE e no saldo da aplicação. A transferência não entra na DRE.
- **No Balanço** (ADR 0167): a 7.1 Disponível continua sendo a soma de todas as contas, então o total não muda
  com a aplicação. Agora ela traz:
  - `checking_cents`, `savings_cents` e `investments_cents`;
  - o `kind` em cada linha de conta;
  - no CSV, a marca "(aplicação)" ou "(poupança)".
  - Não há subconta nova no plano, para não colidir com os códigos que o usuário já criou na 7.1.
- **Razão das contas bancárias** (`GET /api/v1/reports/bank-ledger`):
  - por conta, o saldo anterior (o mesmo da conciliação, `accountBalances`), os recebimentos e pagamentos com
    a conta bancária e as transferências;
  - cada transferência aparece a débito numa conta e a crédito na outra, com `counterpart_bank_account_id`;
  - no total do Disponível, as transferências somam zero.
  - O Livro Razão do plano de contas (ADR 0165) não muda, porque as contas bancárias não são contas do plano.
- **Posição** (`GET /api/v1/investments/positions`): o saldo de cada conta numa data, com o tipo e a conta
  ligada, e os totais separados por tipo.
- **Regras comuns**: escopo por condomínio (fora, 404), 422 para inválido, 409 para competência fechada,
  CSRF no `ApiAuthenticated` e auditoria (`erp_bank_accounts` e `erp_bank_transfers`).
- **Ainda não feito**:
  - o campo de vínculo no formulário do cadastro de contas (é front, e o cadastro está no motor de cadastros);
  - o rendimento automático e a integração com o banco.

## Consequências

- `InvestmentsTest` (4 testes) cobre:
  - as regras do vínculo;
  - aplicar sem mudar o total;
  - o rendimento e o IR na DRE;
  - o balanço fechado com a 7.1 separada;
  - o resgate e o estorno pelo cancelamento;
  - o Razão bancário com os dois lados;
  - a validação, o escopo, a competência fechada e as permissões no controller.
- As fixtures do `BalanceSheetTest` e do `PatrimonialEntriesTest` ganharam `kind` e `parent_bank_account_id`
  em `erp_bank_accounts`.

## Nota da integração na main

Na main, `RegistryService::isClosed` já trava a leitura do fechamento dentro de uma transação (ADR 0100, `LOCK IN SHARE MODE`). Essa sintaxe não existe no SQLite dos testes de unidade, o que quebrava os testes de aplicar e resgatar. Por isso a trava agora só entra quando o driver é `mysql`, que é o MariaDB de produção. No MariaDB nada muda. No SQLite, as gravações já são serializadas.
