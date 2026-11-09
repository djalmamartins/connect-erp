# 0081 — Relatórios de cobrança: acordos, inadimplentes e recebimentos, com CSV seguro

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

O catálogo tem os relatórios de acordos (AP-047) e de taxas de acordos (AP-048), os de inadimplentes
nas visões geral, unidade, detalhada, taxa e morador (AP-057 a AP-061), e os de recebimentos e
recebimentos por conta (AP-071/072). Os dados já existem: lançamentos, acordos e a inadimplência com
os encargos sugeridos (ADR 0039 e 0040). A coordenação pediu: só consulta, sem migration se possível,
escopo por condomínio e exportação em CSV protegida contra injeção de fórmula.

## Decisão

- **`CollectionReports`**, somente leitura e sem migration, calculado na hora (sem tabela própria):
  - **AP-047 Acordos** (`GET /api/v1/reports/agreements`): os acordos criados no período, um por
    linha, com parcelas pagas, em aberto, vencidas e o próximo vencimento. Acordo cancelado aparece,
    mas fica de fora das somas;
  - **AP-048 Taxas de acordos** (`/reports/agreement-fees`): por condomínio, a dívida original, os
    encargos, os descontos, os encargos líquidos (encargos − desconto) e o total. Cancelados só entram
    na contagem;
  - **AP-057 a AP-061 Inadimplentes** (`/reports/delinquents?view=`), sobre a mesma conta da
    inadimplência (`Agreements::delinquency`):
    - `general`: por condomínio;
    - `unit`: por unidade;
    - `detailed`: por conta, com valor + encargos;
    - `fee`: por taxa, isto é, a conta do plano;
    - `resident`: o responsável da unidade (responsável financeiro, depois proprietário), com o
      documento mascarado. Exige também `erp.residents.view`;
  - **AP-071/072 Recebimentos** (`/reports/receipts?group=`): as receitas pagas no período, pela data
    do pagamento, em linhas (com os dias de atraso) ou agrupadas por conta bancária ou por conta do
    plano.
- **Período:** datas AAAA-MM-DD, até 366 dias. A data de referência dos inadimplentes é, por padrão,
  hoje.
- **Escopo:** um condomínio, ou todos os do escopo do usuário (`condominium_id` vazio). Condomínio
  fora do escopo responde 404.
- **CSV** (`?format=csv`, a mesma consulta):
  - BOM UTF-8, separador `;` e linhas CRLF;
  - valores em reais (1234,56) e datas DD/MM/AAAA;
  - **toda célula que começa com `=`, `+`, `-` ou `@`, e também tab ou CR, ganha um apóstrofo na
    frente** (`CollectionReports::csvCell`, sem exceção, inclusive no cabeçalho);
  - a exportação fica na auditoria (`export`, com o relatório e o tamanho), porque a visão por
    morador tem dados pessoais.
- **Permissões existentes:** acordos e inadimplentes exigem `erp.agreements.view`; recebimentos,
  `erp.entries.view`.

## Fora deste ADR

- Os demais relatórios do catálogo (AP-045/046, AP-049/050, AP-052 a AP-056, AP-062 a AP-070 e
  AP-073 em diante) e os gráficos (AP-102 a AP-109) seguem pela ordem do backlog.
- PDF: a tela imprime pelo navegador.

## Consequências

- Telas (layout): os relatórios usam estas rotas, com o botão "Exportar CSV" apontando para a mesma
  URL com `format=csv`.
