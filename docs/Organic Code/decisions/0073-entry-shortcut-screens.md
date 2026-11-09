# 0073 — Telas dos lançamentos avulsos e do recibo (AP-024 a AP-029 e AP-040)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Backend: ADR 0057

## Contexto

O ADR 0057 criou as regras e a API dos lançamentos avulsos: desconto, multa avulsa, cobrança extra,
transferência entre contas e recibo. As páginas do catálogo ainda mostravam a demonstração.

## Decisão

- **AP-024 Lançar despesa e AP-025 Lançar receita:** as rotas do catálogo levam ao "novo" de contas a
  pagar e a receber (`/financeiro/contas-a-pagar/novo` e `/financeiro/contas-a-receber/novo`). Não há
  tela nova: o lançamento é o mesmo, com a natureza fixa (ADR 0024 e 0057).
- **Telas próprias quando há dados** (`EntryShortcutScreens`, chamada pelo `Panel`):
  - **AP-026 Lançar desconto:** lista as contas a receber em aberto do condomínio da barra superior,
    com busca. O botão "Desconto" abre um diálogo com o valor e o motivo, e o diálogo mostra como a
    conta fica.
  - **AP-027 Multa avulsa e AP-028 Cobranças extras:** um modelo só (`pages/finance/extra-charges`).
    - Formulário: a multa é de uma unidade e pede o motivo. A cobrança extra é para várias unidades
      ("Todas" já vem marcado) e escolhe o cálculo.
    - Lista com filtro por situação; `?cobranca=ID` mostra as contas geradas por unidade.
    - Cancelamento com motivo.
  - **AP-029 Outros lançamentos:** formulário de transferência entre duas contas bancárias ativas do
    condomínio. Lista com filtro e cancelamento com motivo.
  - **AP-040 Recibos:** é a mesma tela dos documentos da unidade (ADR 0044), com o tipo `receipt`.
    - `?unidade=` escolhe a unidade; `ReceiptCandidates` traz as contas pagas sem recibo válido,
      até 120, das mais recentes.
    - O usuário marca as contas e vê o total. O recibo impresso lista as contas e o código de
      verificação.
- **Gravação pela API REST** (erp.js, `data-erp-shortcuts`), com CSRF no cabeçalho. A API confere de
  novo a permissão, o escopo e as regras do ADR 0057.
- **Erros no formulário:** o erro do servidor aparece no próprio formulário ou diálogo, com
  `role="alert"`. Antes de enviar, o primeiro campo inválido é apontado com o nome do campo.
- **Gravar exige um condomínio na barra superior.** Em "Todos", as listas mostram o escopo inteiro e os
  formulários pedem a escolha. As contas de receita e as contas bancárias oferecidas são as do
  condomínio e as do padrão da Connect (`EntryShortcutScreens::forContext`).
- **Permissões do menu:**
  - `erp.entries.view` para AP-024, 025, 026 e 029;
  - `erp.invoices.view` para AP-027 e 028;
  - `erp.unit_documents.view` para AP-040.
  - Sem a permissão de gravar (`.manage`), a tela só consulta.

## Consequências

- Não há histórico de descontos na tela de AP-026: a trilha está em
  `GET /api/v1/entries/{id}/adjustments` e pode ir para o detalhe da conta depois.
- A multa avulsa não oferece a "conta de referência" (`related_entry_id`); o campo segue disponível
  na API.
- Não foi testado com MariaDB real. Os testes cobrem a renderização (smoke), as rotas, as
  permissões e o comportamento do JS no navegador com a API simulada.
