# 0057 — Lançamentos avulsos e recibos (AP-024 a AP-029 e AP-040)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

As fichas de AP-024 a AP-029 e AP-040 são só esqueletos: a auditoria não confirmou campos nem
regras. A coordenação pediu atalhos sobre o motor de lançamentos (ADR 0022), com regras definidas por
nós, conservadoras, que respeitem o fechamento mensal (ADR 0031) e deem ao recibo um código de
verificação, como no ADR 0044. As regras ficam na issue #46 para revisão da administração.

## Decisão

- **AP-024 Lançar despesa e AP-025 Lançar receita:** já são as visões de contas a pagar e a receber
  (ADR 0024), com a natureza fixa. Não há código novo; as telas usam essas rotas.
- **AP-026 Desconto** (`POST /api/v1/entries/{id}/discount`):
  - só em conta a receber válida e em aberto;
  - o desconto é menor que o valor e nunca zera a conta (para perdoar, cancela-se o lançamento);
  - recusa parcela ou original de acordo (o valor segue o acordo), boleto ativo (o banco cobraria o
    valor antigo) e competência fechada;
  - motivo obrigatório. O valor da conta muda, e a trilha (`erp_entry_adjustments`) guarda o antes, o
    depois e o motivo, além da auditoria.
- **AP-027 Multa avulsa e AP-028 Cobranças extras** (`/api/v1/extra-charges`), uma origem só
  (`erp_extra_charges`, com `kind` fine ou extra):
  - geram uma conta a receber por unidade, na conta analítica de receita escolhida;
  - a multa é de uma unidade, com valor fixo e motivo obrigatório (regulamento ou ocorrência); pode
    citar a conta a que se refere;
  - a cobrança extra vale para uma ou mais unidades: valor fixo por unidade, total dividido igual (o
    centavo que sobra vai para as primeiras) ou rateio pela fração ideal (`Invoices::apportion`);
  - o vencimento não pode estar no passado, e a competência fechada recusa;
  - as contas ficam travadas no cadastro, como as da fatura e do acordo: `extra_charge_id` entra no
    `origin_lock`, e só a baixa continua livre;
  - o cancelamento é pela origem, com motivo, e só se nenhuma conta estiver paga, em acordo ou com
    boleto ativo, e com a competência aberta.
- **AP-029 Outros lançamentos** = transferência entre contas bancárias do mesmo condomínio
  (`/api/v1/bank-transfers`, `erp_bank_transfers`):
  - não é receita nem despesa: não aparece no balancete;
  - entra no saldo das contas (`BankBalances`) e na conciliação da prestação de contas como entrada
    numa conta e saída na outra, somando zero no condomínio;
  - data até hoje e competência aberta; o cancelamento tem motivo e tira a transferência dos saldos.
- **AP-040 Recibo:**
  - é um tipo novo dos documentos da unidade (`receipt`), com o mesmo código de verificação e a
    mesma foto do conteúdo;
  - cobre contas a receber já pagas da unidade, escolhidas uma a uma (até 60);
  - cada conta só tem um recibo válido (`erp_unit_document_entries`); cancelar o recibo libera a conta;
  - conta com recibo válido não tem a baixa estornada nem alterada (data, conta bancária, valor,
    unidade, situação) e não é excluída: a chave `receipt_lock` do motor
    (`Definitions::RECEIPT_LOCK`, nos cadastros de lançamentos, contas a pagar e contas a receber)
    responde "Cancele o recibo antes de estornar ou alterar a baixa";
  - `UnitDocuments::KINDS` continua com os 4 documentos das páginas AP-123 a AP-130, e o recibo fica
    em `ALL_KINDS`.
- **Permissões existentes:**
  - `erp.entries.*` para desconto e transferência;
  - `erp.invoices.*` para multa e cobrança extra;
  - `erp.unit_documents.*` para o recibo.
- **Migration:** 20261108_0534 cria as tabelas e a coluna `extra_charge_id`, sem permissão nova.

## Fora deste ADR

- Remessa e pagamentos ao banco (AP-031 e AP-037) dependem do Djalma.
- Não há aviso por e-mail de multa ou cobrança extra (pode entrar como evento da outbox depois).
- Não há estorno de desconto: corrige-se com um novo lançamento ou cancelando a conta.

## Consequências

- `FinancialReports::statement` e `BankBalances` passam a mostrar `transfers_in_cents` e
  `transfers_out_cents` por conta.
- Telas (layout): AP-026 a AP-029 e AP-040 usam estas rotas. O recibo usa o modelo de documento da
  unidade, com a lista de contas pagas.
