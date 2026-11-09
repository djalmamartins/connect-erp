# 0027 — Lançamentos fixos (AP-021) e geração do mês sem duplicar

Data: 2026-11-08 · Status: aceito (ciclo autônomo da frente financeira, por delegação do Djalma; aguarda integração no container)

## Contexto

Receitas e despesas que se repetem todo mês (taxa condominial, contrato de portaria, elevador)
precisam virar lançamentos (ADR 0022) a cada competência, sem digitação repetida e sem duplicar
quando a geração é refeita. A página AP-021 "Lançamentos fixos" existia sem dados. A ficha segue
PENDENTE, e os campos são PROPOSTOS.

## Decisão

- **Modelos pelo motor de cadastros** (definição `recurring_entries`, tabela `erp_recurring_entries`,
  migration `20261108_0508`; permissões `erp.recurring_entries.view/manage`, migration `0510`):
  - mesmos campos e regras de um lançamento (natureza, conta analítica da mesma natureza, centro
    de custo, conta bancária sugerida, fornecedor, descrição, valor maior que zero);
  - dia do vencimento (1 a 28, existe em todo mês), primeira competência e última competência
    opcional (`AAAA-MM`, a última não pode ser anterior à primeira).
- **Geração** (`Source\Services\Erp\RecurringEntries::generate`, exposta em
  `POST /api/v1/recurring-entries/generate`, corpo `{competence, condominium_id?}`, com CSRF):
  - só modelos ativos, do escopo do usuário e vigentes na competência;
  - cada lançamento é gravado pelo `RegistryService` (mesmas validações, escopo e auditoria de
    um lançamento digitado) e nasce **em aberto**, com vencimento no dia do modelo;
  - **sem duplicar**: o modelo é travado (`SELECT … FOR UPDATE`), o vínculo existente é pulado
    (`skipped`) e a chave única `(recurring_entry_id, competence)` em `erp_entries` (migration
    `0509`) barra uma chamada concorrente;
  - um modelo com problema (por exemplo, conta inativada) vai para `failed` com as mensagens e
    não impede os demais;
  - exige `erp.recurring_entries.manage` e `erp.entries.manage`.
- O vínculo `erp_entries.recurring_entry_id` não é editável pelo formulário de lançamentos.

## Consequências

- Excluir (exclusão lógica) um lançamento gerado **não** libera a competência para nova geração:
  o vínculo continua na chave única. Isso evita recriar por engano o que foi apagado de propósito.
  Para refazer, ajuste o lançamento existente.
- Alterar o modelo não muda lançamentos já gerados. Vale para as próximas competências.
- O botão "Gerar lançamentos do mês" na tela é da frente de layout (a API já existe). Uma rotina
  agendada (scheduler) pode chamar o mesmo serviço depois.
- Testes: `tests/legacy/Unit/RecurringEntriesDefinitionTest.php` e
  `tests/legacy/Integration/RecurringEntriesTest.php`.
