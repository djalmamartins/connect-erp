# 0019 — Plano de contas (AP-160)

Data: 2026-11-08 · Status: aceito (decidido pela thread de backend no ciclo autônomo de 30 minutos, por delegação do Djalma; aguarda integração no container)

## Contexto

O núcleo financeiro (fase 3 de `docs/process/IMPLEMENTATION_ORDER.md`: plano de contas,
centros de custo, contas bancárias, lançamentos) não tinha nenhuma implementação em nenhum
branch. O catálogo do ERP já tinha a página AP-160 "Plano de Contas" em Configurações, sem
dados. A ficha AP-160 segue com campos PENDENTES (auditoria somente leitura). Os campos e
regras abaixo são PROPOSTOS.

## Decisão

- **Motor único de cadastros** (definição `chart` em `Definitions.php`, ADR 0014), com escopo
  `optional`:
  - sem condomínio, a conta é do **plano padrão da Connect** e vale para todos os condomínios;
    criar, alterar ou excluir essas contas exige acesso a todos os condomínios
    (`erp.condominiums.all`);
  - com condomínio, a conta é própria daquele condomínio e completa o padrão.
- **Tabela `erp_chart_accounts`** (migration `20261108_0405`): código, nome, natureza (receita
  ou despesa), tipo (sintética, que agrupa, ou analítica, que recebe lançamentos), observações,
  situação, exclusão lógica. Permissões `erp.chart.view` e `erp.chart.manage` (migration `0406`).
- **Código hierárquico** `1`, `1.01`, `1.01.002` (até 6 níveis), com regras genéricas no motor
  (`hierarchy`):
  - a conta-pai (código sem o último segmento) precisa existir ativa no mesmo plano ou no
    padrão, ser sintética e ter a mesma natureza;
  - código único no plano padrão e em cada condomínio; uma conta de condomínio não pode repetir
    código do padrão;
  - conta com contas-filhas ativas não pode ser excluída (`RegistryInUse`, 409 na API). Uma
    conta do padrão com filhas em qualquer condomínio também não.
- A mensagem de "cadastro para todos os condomínios" do motor deixou de ser fixa em "contato".
- Não há carga inicial de um plano padrão: o conteúdo é decisão de negócio.

## Consequências

- A ordenação é por texto do código: `1.10` aparece antes de `1.2`. Use segmentos com o mesmo
  número de dígitos (`1.02`, `1.10`) até haver ordenação natural.
- A próxima etapa natural é usar a conta analítica nos lançamentos e nos centros de custo. O
  saldo inicial por conta (AP-161) depende dos lançamentos.
- Testes: `tests/legacy/Unit/ChartOfAccountsDefinitionTest.php` e
  `tests/legacy/Integration/ChartOfAccountsTest.php`.
