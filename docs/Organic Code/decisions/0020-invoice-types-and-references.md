# 0020 — Tipos de faturas (AP-168) e campo de referência entre cadastros

Data: 2026-11-08 · Status: aceito (ciclo autônomo da frente financeira, por delegação do Djalma; aguarda integração no container)

## Contexto

O financeiro é desta frente (coordenação, 08/11). O plano de contas (ADR 0019) existe, mas o
catálogo do ERP não tem página de centros de custo nem de contas bancárias: ele é gerado de
`docs/specs/routes.json` (auditoria APControle) por `tools/erp-catalog.py`. A página AP-168
"Tipos de Faturas" (Configurações) existia sem dados e é a primeira peça da cobrança: define o
que se cobra das unidades e em qual conta de receita entra. A ficha AP-168 segue com campos
PENDENTES. Os campos abaixo são PROPOSTOS.

## Decisão

- **Novo tipo de campo `ref` no motor de cadastros**, para apontar para um registro de outro
  cadastro (`'ref' => slug`, `'ref_where' => filtros`, `'ref_label' => colunas`):
  - o registro apontado precisa existir, estar ativo e atender aos filtros;
  - o registro apontado precisa valer para o condomínio: registro do padrão (sem condomínio)
    vale para todos, e registro sem condomínio só aponta para o padrão;
  - o formulário lista as opções do escopo do usuário, agrupadas em "Padrão da Connect" e por
    condomínio;
  - quem é apontado não pode ser excluído enquanto houver registro ativo apontando
    (`RegistryInUse`, 409 na API). Isso vale para qualquer cadastro, sem configuração extra.
  - Lançamentos, centros de custo e contas bancárias vão reutilizar este tipo.
- **Tipos de faturas** (`invoice_types`, tabela `erp_invoice_types`, migration `20261108_0501`;
  permissões `erp.invoice_types.view/manage`, migration `0502`):
  - escopo `optional`: sem condomínio = tipo padrão da Connect, e só quem acessa todos os
    condomínios cria ou altera esses tipos;
  - campos: nome (único por plano), conta de receita (`ref` para uma conta **analítica** de
    **receita** do plano de contas), recorrência (mensal ou avulsa), valor padrão em centavos
    (opcional) e observações.
- As migrations do financeiro usam a faixa `20261108_05xx`.

## Consequências

- Uma conta do plano de contas usada por um tipo de fatura ativo não pode ser excluída.
- Para ter centros de custo e contas bancárias como páginas do ERP, é preciso incluí-los no
  catálogo (`docs/specs/routes.json` + `tools/erp-catalog.py`). Isso fica para um ciclo com
  acesso de escrita ao gerador, ou para uma decisão da frente de layout.
- Testes: `tests/legacy/Unit/InvoiceTypesDefinitionTest.php` e
  `tests/legacy/Integration/InvoiceTypesTest.php`.
