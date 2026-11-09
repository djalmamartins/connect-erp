# 0033 — Faturas (AP-032): cobrança por unidade que vira contas a receber

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O passo 4 do `docs/process/IMPLEMENTATION_ORDER.md` pede faturas com estados e idempotência. Já existem
os tipos de faturas (AP-168, ADR 0020: o que se cobra e em qual conta de receita entra), as unidades
(AP-006, com fração ideal), os lançamentos e contas a receber (ADR 0022 e 0024) e o fechamento
mensal (ADR 0031). A ficha `ap-032-faturas.md` ainda é um scaffold; as regras abaixo são PROPOSTAS.

## Decisão

- **Fatura = um tipo de fatura cobrado de todas as unidades ativas de um condomínio numa competência**
  (`erp_invoices` + `erp_invoice_items`, um item por unidade). Estados: `draft` (em preparação) →
  `issued` (emitida) → `cancelled`.
- **Uma fatura ativa por condomínio, tipo e competência.** A coluna gerada `active_key` vale 1 enquanto
  a fatura não está cancelada; a chave única ignora NULL, então cancelar libera para preparar outra.
  Tentar preparar a segunda responde 409.
- **Valor:**
  - `fixed`: cada unidade paga `amount_cents`; sem valor informado, usa o valor padrão do tipo;
  - `fraction`: o total é rateado pela fração ideal. Os centavos que sobram vão para as maiores
    sobras, e a soma sempre fecha (`Invoices::apportion`, regra pura). Unidade sem fração = 422.
  - No rascunho, o valor de cada unidade pode ser ajustado.
- **Emitir** cria uma conta a receber por item, pelo `RegistryService`, com as mesmas regras dos
  lançamentos (conta de receita do tipo, unidade do mesmo condomínio, trava do fechamento mensal):
  - é tudo ou nada, numa transação;
  - `erp_entries.invoice_item_id`, com chave única, liga o lançamento ao item e barra emitir duas vezes;
  - `erp_entries.unit_id` guarda a unidade que deve.
- **Lançamento gerado por fatura fica travado** (`origin_lock` nas definições de lançamentos e contas
  a pagar e a receber):
  - não troca condomínio, natureza, conta, valor, competência, vencimento, unidade, situação nem
    descrição, e não é excluído;
  - a baixa (pago em, conta bancária), o número do documento e as notas continuam livres.
  - Unidade com lançamento não pode ser excluída (`referenced_by`).
- **Cancelar** exige motivo (5 a 255 caracteres):
  - em preparação, só muda o estado;
  - emitida: recusa se alguma conta a receber já foi paga ou se a competência está fechada. Sem
    isso, os lançamentos gerados ficam cancelados (`status inactive`), e o histórico fica.
- **Escopo:** fatura de condomínio fora do escopo responde 404. A lista aceita `condominium_id` vazio
  (todos do escopo). A tela AP-032 recebe `$invoices` no contexto da barra superior (ADR 0030/0032)
  e `$canManageInvoices`.
- **Permissões** `erp.invoices.view` e `erp.invoices.manage` (developer e super_admin). Preparar,
  ajustar, emitir e cancelar são auditados.
- **API REST** `/api/v1/invoices` (`docs/api/openapi.yaml`). Migrations `20261108_0513` a `0515`.

## Consequências

- O próximo passo (boleto, remessa e retorno) parte das contas a receber com `invoice_item_id`.
  A baixa pelo retorno bancário preenche `paid_on` sem esbarrar na trava.
- Ainda não há: fatura só para algumas unidades, multa ou juros, nem segunda via.
- A frente de layout precisa montar a tela AP-032 (lista, preparar, ajustar, emitir e cancelar)
  sobre `$invoices` e a API.
